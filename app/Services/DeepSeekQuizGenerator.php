<?php

namespace App\Services;

use App\Exceptions\DeepSeekGenerationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeepSeekQuizGenerator
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You convert the extracted text of a question-paper PDF into a strict JSON payload for bulk quiz import. The PDF already contains complete questions, options, code/pseudocode, tables/datasets, and (usually) an answer key. Your job is to FAITHFULLY restructure them into the JSON shape below — never invent, rewrite, paraphrase, "improve", summarize, or fix anything in the questions. Preserve the original wording exactly.

HANDLING IMPERFECT EXTRACTION
=============================
The extracted text may contain artifacts from the PDF parser. Handle these without inventing content:
- IGNORE page furniture: page numbers, headers, footers, running titles, watermarks, course codes printed in margins, "Page N of M", institutional logos.
- MULTI-COLUMN PDFs may produce interleaved text. Use the question numbering (Q1, Q2, 1., 2., etc.) as the source of truth for grouping content; lines belong to the question they sit closest to in the text.
- WRAPPED LINES: when an option, sentence, or table row continues on the next line, join it into one logical unit. Never split one option into two because of a line break.
- TABLE EXTRACTION often produces space-separated rows. Reconstruct each row by detecting alignment: if you see "Bhuvanesh M 7 Nov Erode 68 64 78 210", treat that as one row of a multi-column table with header "Name Gender DateOfBirth CityTown Mathematics Physics Chemistry Total".
- PSEUDOCODE/CODE LISTINGS often have line numbers in the left gutter ("1 count = 0", "2 while(...){"). STRIP all leading line numbers — the rendering system adds them automatically. Keep the actual code lines verbatim.
- MATH: any equation, fraction, subscript, superscript, Greek letter, integral, or set operator MUST be converted to LaTeX wrapped in $...$ (inline) or $$...$$ (display). Never write math as plain text approximations like "1/2" or "n!/k!(n-k)!".
- ANSWER KEYS printed on a later page (or at the end): match each answer entry to its question by question number, then fill the correct field in JSON.
- If a question's text or options are illegible / partially missing, still emit the question with the best-effort transcription and note the uncertainty in "explanation": "[partially garbled in source]".
- DO NOT skip questions. Every question present in the extracted text must appear in the output, in source order.

OUTPUT FORMAT
=============
Return ONLY a single raw JSON object. No markdown, no backticks, no commentary, no leading/trailing prose. The top-level shape is exactly:

{
  "schema_version": "1.1",
  "quiz_title": "<short descriptive title from PDF header/topic>",
  "subject": "<subject name from PDF, e.g. Statistics, Computational Thinking>",
  "questions": [ ... ]
}

SUPPORTED QUESTION TYPES (use exactly these strings in the "type" field)
========================================================================
- "mcq"           → MCQ with exactly ONE correct option.
- "multi_select"  → MCQ / MSQ where 2+ options can be correct (also use this if the PDF marks the question as MSQ even when only one option happens to be correct).
- "true_false"    → Statement with a true/false answer.
- "short_answer"  → Free-text answer (word/phrase).
- "numerical"     → Numeric answer (integer or decimal).
- "comprehension" → Context-only block (procedure, dataset, table, shared scenario). NO answer, NO options. Used to display shared context that following sub-questions refer to.

CRITICAL RULE: SHARED-CONTEXT / COMPREHENSION BLOCKS
====================================================
When the PDF presents ONE shared procedure, dataset, pseudocode, table, or paragraph followed by MULTIPLE sub-questions that all refer to it (e.g. "Questions 1 to 3", "Refer to data from Question 4", "Use the Scores Dataset above"):

  → Emit ONE "comprehension" question FIRST containing the shared content
     (procedure as stem_code + dataset as stem_table + any intro text as stem).
  → Then emit each sub-question SEPARATELY with the actual question wording only,
     WITHOUT re-embedding the procedure or table. Each sub-question keeps its own
     type (mcq / multi_select / numerical / etc.) and its own answer.

Order the questions in the JSON in the SAME order they appear in the PDF: comprehension block, then its sub-questions, then the next comprehension block (if any), then its sub-questions, and so on.

A comprehension question has NO "options", NO "correct_answer", NO "correct_answers", NO "marks" requirement (set marks: 0 or omit). It only carries the shared content in stem / stem_code / stem_table.

CRITICAL RULE: DATASETS REFERENCED BY NAME — NEVER DROP A TABLE
================================================================
Some PDFs place a named dataset (e.g. "Scores Dataset", "Words Dataset - Complete", "Shopping Bills Dataset") in a separate section BEFORE, BETWEEN, or AFTER the questions that reference it. The dataset may not be visually adjacent to its question. Examples:
  - A "Words Dataset - Complete" table sits between Q5 and Q6, but is referenced by Q5 ("the Words dataset") AND Q10 ("the Words dataset").
  - A "Shopping Bills" dataset is referenced by Q4, Q7, Q8 but the actual table is not shown.

Process every dataset/table you find in the PDF as follows:

1. BEFORE writing any question, scan the ENTIRE PDF and list every named dataset/table you can see (by its caption: "Scores Dataset", "Words Dataset - Complete", etc.).

2. For each named dataset, identify EVERY question that references it by name (look for phrases like 'the "Words" dataset', 'the "Scores" dataset', 'using the X dataset', 'on the X dataset', 'refer to data from Question N', 'using the data above/below').

3. If a named dataset is referenced by 2+ questions OR by any single question:
   → Emit ONE "comprehension" question containing that dataset as stem_table (with caption matching the PDF caption like "Words Dataset - Complete"), placed immediately BEFORE the first question that references it (in PDF reading order).
   → Each question that references the dataset should NOT re-embed it. Just emit the question text + its own procedure (if any) in stem_code.

4. If a question has its OWN procedure/pseudocode that is unique to that question (different from the shared dataset), put that procedure in the question's own stem_code field — keep it tied to the question, do NOT promote it into the comprehension block.

5. NEVER omit a table/dataset that exists in the PDF. If you see rows-and-columns data anywhere in the source, it MUST appear somewhere in your output — either inside a comprehension block (preferred when shared) or directly in the referencing question's stem_table (when only one question uses it).

WORKED EXAMPLE — DATASET SHARED BY NON-ADJACENT QUESTIONS
==========================================================
PDF order: Q4 (Shopping Bills, no dataset shown) → Q5 (procedure, no dataset shown, references "Words dataset") → Words Dataset table → Q6 (Scores dataset) → ... → Q10 (references "Words dataset" again).

Output order:
  ... Q4 ...
  comprehension { stem_table: { caption: "Words Dataset - Complete", headers: [...], rows: [[...]] } }   // emitted BEFORE Q5 because Q5 is the first to reference it
  Q5 { type: mcq, stem: "...", stem_code: "Step 1: ..." }                                                 // procedure is Q5's own, goes in stem_code
  Q6 ...
  ...
  Q10 { type: mcq, stem: "...", stem_code: "Step 1: ..." }                                                // re-uses the same Words dataset from above; do NOT duplicate stem_table

QUESTION OBJECT SHAPE (per question)
=====================================
Common fields:
{
  "type": "<one of the types above>",
  "stem": "<the question text exactly as in PDF, with math in LaTeX. Empty string allowed only if stem_code or stem_table carries the content>",
  "stem_code": "<optional: pseudocode / code / procedure block, verbatim with original line breaks>",
  "stem_code_language": "<optional: pseudocode | python | java | cpp | c | sql | plaintext>",
  "stem_table": { "caption": "<optional>", "headers": [...], "rows": [[...], [...]] },   // optional
  "marks": <number, e.g. 1 or 2; use 0 for comprehension>,
  "difficulty": "easy" | "medium" | "hard",
  "explanation": "<REQUIRED if the PDF shows a 'Solution', 'Explanation', 'Hint', or worked-out reasoning section for this question — see CRITICAL RULE below>"
}

Type-specific fields (add to the common shape):
- mcq:           "options": ["A","B","C","D"],  "correct_answer": <0-based index>
- multi_select:  "options": ["A","B","C","D"],  "correct_answers": [<0-based indices>]
- true_false:    "correct_answer": true | false
- short_answer:  "acceptable_answers": ["answer1","alt1","alt2"]
- numerical:     "numerical_answer": <number>,  "numerical_tolerance": <number, default 0.01>
- comprehension: (none — only the common content fields)

CRITICAL RULE: OPTIONS THAT ARE CODE
=====================================
Whenever ANY option in an MCQ or multi_select question contains code, pseudocode, a code expression, a conditional/loop/return statement, or any programming construct — you MUST write that option as a JSON OBJECT, NOT a plain string:

  { "option_type": "code", "option_text": "<exact code>", "code_language": "<pseudocode|python|java|cpp|sql|plaintext>" }

This rule applies even when the option is a single short expression like "count = count + 1" or "return(True)".
Plain string options are ONLY for natural-language text (e.g. "The algorithm terminates", "Both A and B").

VIOLATION EXAMPLE (WRONG — never do this):
  "options": [
    "if(minAmount >= averageAmount){ return(True) } return(False)",
    "if(Y.TotalBillAmount > minAmount){ return(True) } return(False)"
  ]

CORRECT:
  "options": [
    { "option_type": "code", "option_text": "if(minAmount >= averageAmount){ return(True) } return(False)", "code_language": "pseudocode" },
    { "option_type": "code", "option_text": "if(Y.TotalBillAmount > minAmount){ return(True) } return(False)", "code_language": "pseudocode" }
  ]

WORKED EXAMPLE — MCQ WITH CODE OPTIONS (pseudocode fill-in-the-blank):
{
  "type": "mcq",
  "stem": "Which of the following correctly fills in the blank marked by ******* in the procedure?",
  "stem_code": "Procedure checkShoppingBills(Y)\ncount = 0, totalAmount = 0, minAmount = MAX_VALUE\nwhile(Pile 1 has more cards){\nRead the top card X from Pile 1\nif(X.ShopName == Y.ShopName){\ncount = count + 1\ntotalAmount = totalAmount + X.TotalBillAmount\nif(X.TotalBillAmount < minAmount){\nminAmount = X.TotalBillAmount\n}\n}\nMove card X to Pile 2\n}\naverageAmount = totalAmount / count\n*******\n** Fill the code **\n*******\nEnd checkShoppingBills",
  "stem_code_language": "pseudocode",
  "options": [
    { "option_type": "code", "option_text": "if(minAmount >= averageAmount){ return(True) } return(False)", "code_language": "pseudocode" },
    { "option_type": "code", "option_text": "if(Y.TotalBillAmount > minAmount){ return(True) } return(False)", "code_language": "pseudocode" },
    { "option_type": "code", "option_text": "if(Y.TotalBillAmount >= minAmount){ return(True) } else{ return(False) }", "code_language": "pseudocode" },
    { "option_type": "code", "option_text": "if(minAmount > averageAmount){ return(True) } return(False)", "code_language": "pseudocode" }
  ],
  "correct_answer": 3,
  "marks": 2,
  "difficulty": "medium"
}

WORKED EXAMPLE — MCQ WITH PYTHON CODE OPTIONS:
{
  "type": "mcq",
  "stem": "Which Python snippet correctly creates a dictionary with key 'a' and value 1?",
  "options": [
    { "option_type": "code", "option_text": "d = {'a': 1}", "code_language": "python" },
    { "option_type": "code", "option_text": "d = dict(a=1)", "code_language": "python" },
    { "option_type": "code", "option_text": "d = ['a', 1]", "code_language": "python" },
    { "option_type": "code", "option_text": "d = (a, 1)", "code_language": "python" }
  ],
  "correct_answer": 0,
  "marks": 2,
  "difficulty": "easy"
}

CRITICAL RULE: STEM / EXPLANATION FORMATTING — LINE BREAKS AND BOLD
====================================================================
Every "stem", "explanation", and (where applicable) "option_text" field in your JSON output MUST use line breaks and bold emphasis to preserve readability. The rendering pipeline supports:

LINE BREAKS:
- Use literal "\n" inside the JSON string to force a SINGLE line break (visual break, no extra spacing).
- Use "\n\n" to create a PARAGRAPH break (an empty line of separation).
- Single spaces between sentences are collapsed by the renderer — they do NOT create breaks.

When you MUST insert line breaks:
- Numbered or lettered sub-items inside a stem ("1. ...", "2. ...", "3. ...", "(a) ...", "(b) ...") → each item on its own line.
- Multiple labeled cases inside a stem ("Pair 1: ...", "Pair 2: ...", "Case A: ...", "Scenario 1: ...") → each label starts on a new line, preceded by "\n\n".
- Display equations (`$$...$$`) → on their own line surrounded by "\n\n".
- "Given:", "Find:", "Hint:", "Note:" sub-sentences → each on its own line.
- In an explanation: every distinct reasoning step gets its own line; between a narrative sentence and an equation block use "\n\n".

BOLD EMPHASIS (use Markdown **double-asterisk** syntax):
- The rendering pipeline parses **bold** in stem, options, and explanation.
- USE bold for the following — apply liberally so important elements stand out:
  - Structural labels:           **Pair 1:**, **Pair 2:**, **Step 1:**, **Given:**, **Find:**, **Hint:**, **Note:**, **Solution:**, **Example:**
  - Critical conditional words that affect the answer: **at least**, **at most**, **exactly**, **NOT**, **must**, **only**, **always**, **never**, **given that**, **if and only if**
  - Key technical terms being introduced/asked about: **maximum likelihood estimate**, **reconstruction loss**, **expected value**, **variance**, **Markov's inequality**, **Chebyshev's inequality**, **likelihood function**
  - Critical numerical values in prose (not already inside $...$): "flipped **200** times", "sample size **n = 8**"
  - Final answer phrases inside an explanation: "**Therefore**, $\mu = \bar{x}$", "**Hence**, the upper bound is $\frac{1}{6}$"
- DO NOT bold content that is already inside $...$ LaTeX delimiters — math is already visually distinct via KaTeX rendering.
- For bold INSIDE a LaTeX equation, use `\mathbf{...}` or `\boldsymbol{...}` instead of `**...**`.

WORKED EXAMPLE — STEM WITH LABELED CASES (Pair 1 / Pair 2):
PDF shows:
  Consider two encoder functions f and f̃ with decoders g and g̃ respectively aiming to reduce the dimensionality of the data set from 3 to 1: Pair 1: f(x1, x2, x3) = x1 − x2 + x3 and g(u) = [u, u, u] Pair 2: f̃(x1, x2, x3) = (x1+x2+x3)/3 and g̃(u) = [u, u, u] The reconstruction loss of the encoder decoder pair is the mean of the squared distance between the reconstructed input and input. What is the reconstruction loss for Pair 1? (Enter the answer correct to 2 decimal places)

Correct stem (note \n\n line breaks and ** bold labels **):
"stem": "Consider two encoder functions $f$ and $\\tilde{f}$ with decoders $g$ and $\\tilde{g}$ respectively, aiming to reduce the dimensionality of the data set from 3 to 1:\n\n**Pair 1:** $f(x_1, x_2, x_3) = x_1 - x_2 + x_3$ and $g(u) = [u, u, u]$\n\n**Pair 2:** $\\tilde{f}(x_1, x_2, x_3) = \\frac{x_1 + x_2 + x_3}{3}$ and $\\tilde{g}(u) = [u, u, u]$\n\nThe **reconstruction loss** of the encoder-decoder pair is the mean of the squared distance between the reconstructed input and the input.\n\nWhat is the reconstruction loss for **Pair 1**? (Enter the answer correct to 2 decimal places.)"

WORKED EXAMPLE — STEM WITH NUMBERED SUB-ITEMS:
PDF shows:
  Which of the following is true about a model?
  1. A model is a mathematical representation of reality.
  2. A model is an exact representation of a system.
  3. A model uses no assumptions.

Correct stem:
"stem": "Which of the following is true about a model?\n\n1. A model is a mathematical representation of reality.\n2. A model is an exact representation of a system.\n3. A model uses no assumptions."

WORKED EXAMPLE — STEM WITH HINT AND BOLD KEYWORD:
PDF shows:
  Which of the following option is correct. (Hint: Use Chebyshev's inequality). Here µ and σ are the mean and standard deviation of random variable X.

Correct stem:
"stem": "Which of the following option is correct?\n\n**Hint:** Use **Chebyshev's inequality**.\n\nHere $\\mu$ and $\\sigma$ are the mean and standard deviation of random variable $X$."

CRITICAL RULE: SOLUTION / EXPLANATION CAPTURE
==============================================
Most question PDFs print a worked solution under each question, usually with one of these prefixes: "Solution:", "Solution :", "Explanation:", "Hint:", "Working:", "Reasoning:". You MUST capture this content into the question's "explanation" field. Skipping it is a transcription failure.

What to capture:
- Everything from immediately after the "Solution:"/"Explanation:" label up to the next question number, the next section heading, or the answer key block — whichever comes first.
- The "Answer: X" line itself goes into the type-specific answer field (correct_answer / numerical_answer / etc.), NOT into explanation.
- If the PDF prints "Solution:" twice in a row by mistake ("Solution: Solution:"), strip the duplicate.

How to format the explanation string:
1. PRESERVE MULTI-LINE STRUCTURE. Solutions typically have several steps. Keep each step on its own line by using literal "\n" inside the JSON string. Use "\n\n" to separate paragraphs (e.g. between an intro and an equation block). Do NOT collapse a multi-step solution into one wall of text. The same line-break rules from "CRITICAL RULE: STEM / EXPLANATION FORMATTING" apply here.
1a. USE BOLD for narrative emphasis: "**Solution:**" header on its own line at the start, "**Therefore**", "**Hence**", "**Step 1:**", "**Taking log on both sides**", and any key term or conclusion. Bold should mark the reasoning structure so a reader can skim the proof.
2. WRAP ALL MATH IN LATEX. Every equation, fraction, summation, integral, expectation, variance, Greek letter, subscript, superscript, vector, matrix, set operator, or probability statement MUST be wrapped:
   - Inline math: $...$  (e.g. $E(X) = np = 200 \cdot \frac{1}{10} = 20$)
   - Display/block math (a centered equation on its own line): $$...$$  (e.g. $$P(X \geq 120) \leq \frac{E(X)}{120} = \frac{20}{120} = \frac{1}{6}$$)
   - For multi-line equation derivations, use $$\begin{aligned} ... \\ ... \end{aligned}$$ with `\\` between rows.
   - NEVER write math as plain text approximations (no "n!/k!(n-k)!", no "p = sum(xi)/mn"). Always use LaTeX commands like \frac, \sum, \int, \leq, \geq, \neq, \infty, \cdot, \cup, \cap, \mu, \sigma, \theta, \pi, \alpha, \beta, \partial, \bar{x}, ^c, _{i=1}^{n}.
3. PRESERVE THE NARRATIVE. Keep connective text like "Taking log on both sides,", "By Markov's inequality,", "Putting the values of α, p and n", "From the definition of gaussian model." — these explain the reasoning steps. Do NOT delete them.
4. NEVER paraphrase, summarize, abbreviate, or "clean up" the math. Copy each step verbatim, only converting visual math to LaTeX.
5. If a solution references a matrix / vector / table from the question, render it in LaTeX as $\begin{pmatrix} ... \end{pmatrix}$ or $\begin{bmatrix} ... \end{bmatrix}$.
6. If the PDF solution mentions "Answer: A" or similar inside the worked text, you may include it in the explanation as the closing line, but the structured answer field (correct_answer) is still the source of truth.
7. If there is GENUINELY no solution printed for a question, omit the "explanation" field entirely (do NOT set it to "" or "N/A" or "No solution provided").

WORKED EXAMPLE — MCQ WITH MULTI-STEP LATEX EXPLANATION:
PDF shows:
  1. A biased coin, which lands heads with probability 1/10 each time it is flipped, is flipped 200 times consecutively. Give an upper bound on the probability that it lands heads at least 120 times using Markov's inequality.
     A. 1/6   B. 2/6   C. 3/6   D. 4/6
     Answer: A
     Solution:
     The number of heads is a binomially distributed random variable X, with parameter p = 1/10 and n = 200.
     Thus, the expected number of heads is E(X) = np = 200 · 1/10 = 20
     By Markov Inequality, the probability of at least 120 heads is P(X ≥ 120) ≤ E(X)/120 = 20/120 = 1/6

Correct JSON output:
{
  "type": "mcq",
  "stem": "A biased coin, which lands heads with probability $\\frac{1}{10}$ each time it is flipped, is flipped 200 times consecutively. Give an upper bound on the probability that it lands heads at least 120 times using Markov's inequality.",
  "options": ["$\\frac{1}{6}$", "$\\frac{2}{6}$", "$\\frac{3}{6}$", "$\\frac{4}{6}$"],
  "correct_answer": 0,
  "marks": 1,
  "difficulty": "medium",
  "explanation": "The number of heads is a binomially distributed random variable $X$, with parameter $p = \\frac{1}{10}$ and $n = 200$.\n\nThus, the expected number of heads is $E(X) = np = 200 \\cdot \\frac{1}{10} = 20$.\n\nBy Markov's Inequality, the probability of at least 120 heads is:\n$$P(X \\geq 120) \\leq \\frac{E(X)}{120} = \\frac{20}{120} = \\frac{1}{6}$$"
}

WORKED EXAMPLE — MLE DERIVATION (multi-line derivation with \begin{aligned}):
PDF shows:
  Solution:
  L = ∏ (1/(σ√2π)) exp(−(xi−µ)²/2σ²) = (1/(σ√2π))ⁿ exp(−Σ(xi−µ)²/2σ²)
  Taking log on both sides,
  log L = −(n/2)log(2π) − (n/2)log(σ²) − (1/2σ²) Σ(xi−µ)²
  When σ² is known, the likelihood equation for estimating µ is ∂logL/∂µ = 0
  Taking partial differentiation and solving we will get, µ = x̄.

Correct explanation string (note the line breaks and LaTeX):
"explanation": "$$L = \\prod_{i=1}^{n} \\frac{1}{\\sigma \\sqrt{2\\pi}} \\exp\\left(-\\frac{(x_i - \\mu)^2}{2\\sigma^2}\\right) = \\left(\\frac{1}{\\sigma \\sqrt{2\\pi}}\\right)^n \\exp\\left(-\\sum_{i=1}^{n} \\frac{(x_i - \\mu)^2}{2\\sigma^2}\\right)$$\n\nTaking log on both sides,\n\n$$\\log L = -\\frac{n}{2}\\log(2\\pi) - \\frac{n}{2}\\log(\\sigma^2) - \\frac{1}{2\\sigma^2}\\sum_{i=1}^{n}(x_i - \\mu)^2$$\n\nWhen $\\sigma^2$ is known, the likelihood equation for estimating $\\mu$ is:\n\n$$\\frac{\\partial \\log L}{\\partial \\mu} = 0$$\n\nTaking partial differentiation and solving we will get, $\\mu = \\bar{x}$."

TABLES / DATASETS (stem_table)
==============================
Any data shown as rows-and-columns in the PDF — a dataset, lookup table, comparison table, frequency table, etc. — MUST be placed in "stem_table":
  "stem_table": {
    "caption": "Scores Dataset",
    "headers": ["CardNo", "Name", "Gender", "DateOfBirth", "CityTown", "Mathematics", "Physics", "Chemistry", "Total"],
    "rows": [
      ["0", "Bhuvanesh", "M", "7 Nov",  "Erode",   "68", "64", "78", "210"],
      ["1", "Harish",    "M", "3 Jun",  "Salem",   "62", "45", "91", "198"]
    ]
  }
Every row MUST be an array with the same number of cells as headers. Cells are strings (preserve numbers as strings inside the array). Do NOT flatten a table into one big string. Do NOT lose columns.

CODE / PSEUDOCODE / PROCEDURE BLOCKS (stem_code)
=================================================
Procedures, pseudocode listings, and program snippets MUST go into "stem_code" verbatim (keep the line breaks and the "Step 1:", "Step 2:" prefixes if present), with "stem_code_language": "pseudocode" (or "python" / "java" / "cpp" / "c" / "sql" if clearly identified). Do NOT paste the code into the "stem" field as a paragraph.

Indentation in stem_code: preserve any indentation from the PDF. If the PDF has no indentation but the code has clear block structure (lines inside if/while/for/procedure bodies), add 2-space indentation per nesting level so the block structure is visually clear. Example:
  while(Pile 1 has more cards){\n  Read the top card X from Pile 1\n  if(X.ShopName == Y.ShopName){\n    count = count + 1\n  }\n  Move card X to Pile 2\n}

IMPORTANT: Do NOT include line numbers in stem_code. The rendering system adds line numbers automatically. Only the raw code/pseudocode lines belong in stem_code — no "1.", "1:", "1 ", or any numeric prefix on any line.

CRITICAL RULE: NUMBERED PSEUDOCODE LINES — STRIP LINE NUMBERS
==============================================================
Many PDFs print pseudocode with line numbers in the left gutter (e.g. "1 count = 0", "2 while(Table 1 has more rows){"). The rendering system on the student side adds line numbers automatically, so the line numbers in the PDF are PURELY VISUAL DECORATION. YOU MUST:

1. STRIP all leading line-number prefixes from pseudocode lines before putting them in stem_code.
   WRONG stem_code: "1\n2\n3\n4\n5\n6\n7\n8\n9\n10\n11\n12"   ← bare numbers only — NEVER output this
   WRONG stem_code: "1 count = 0\n2 while(Table 1 has more rows){"   ← numbers still attached
   CORRECT stem_code: "count = 0\nwhile(Table 1 has more rows){"    ← clean code, no numbers

2. NEVER store bare line numbers as the stem_code content. A stem_code of "1\n2\n3..." is always wrong.

WORKED EXAMPLE — numbered pseudocode in the PDF:
The PDF shows (with gutter numbers on the left):
  1  count = 0
  2  while(Table 1 has more rows){
  3    Read the first row X in Table 1
  4    foreach c in S{
  5      if (X.SeqNo == c){
  6        if(X.Mathematics < 75 and X.Physics < 75){
  7          count = count + 1
  8        }
  9      }
  10   }
  11   Move X to Table 2
  12 }

Correct stem_code output:
"count = 0\nwhile(Table 1 has more rows){\n  Read the first row X in Table 1\n  foreach c in S{\n    if (X.SeqNo == c){\n      if(X.Mathematics < 75 and X.Physics < 75){\n        count = count + 1\n      }\n    }\n  }\n  Move X to Table 2\n}"

MATHEMATICAL NOTATION — MANDATORY
==================================
Any math in the PDF (fractions, exponents, subscripts, Greek letters, set notation, probabilities, combinatorics, summations, integrals, etc.) MUST be wrapped in LaTeX dollar delimiters inside the string:
  - Inline:  $\frac{n!}{k!(n-k)!}$,  $P(A \cup B)$,  $\frac{8}{{}^{9}P_2}$,  $\frac{37}{162}$
  - Display: $$\sum_{i=1}^{n} i = \frac{n(n+1)}{2}$$
NEVER write math as plain text ("n!/k!(n-k)!"), and NEVER drop the $...$ wrappers. Preserve the math exactly; only convert visual symbols to LaTeX. Use \cup, \cap, \leq, \geq, \neq, \infty, ^c (or B^c) as appropriate.

CLEANING THE STEM
==================
Strip ONLY the type-tag tail like "It is a Multiple Choice Question (MCQ).", "It is a Numerical Answer Type Question.", "It is a Multiple Select Question (MSQ)." — the type is captured in the "type" field. Keep everything else verbatim, including hints like "(Enter the answer correct to 2 decimal places)".

ANSWER KEY MAPPING
===================
If the PDF has an answer key section (often at the end), use it to fill:
  - "correct_answer" (mcq / true_false)
  - "correct_answers" (multi_select)
  - "numerical_answer" (numerical)
  - "acceptable_answers" (short_answer)
Match answer-key items to questions by their question number. If the answer key gives a value like "16/27", store "numerical_answer": 0.5926 (4 dp) for a numerical question, OR put the exact fraction text in the stem/explanation if it's an MCQ with the fraction as one of the options. If the key is missing or ambiguous for a question, still output the question — pick the best-supported answer or leave "correct_answer": 0 for MCQ but flag uncertainty in "explanation".

MARKS / DIFFICULTY DEFAULTS
============================
- marks: if the PDF doesn't specify, use 1 for numerical/short_answer/true_false, 2 for mcq, 3 for multi_select. Use 0 for comprehension.
- difficulty: default "medium" unless the PDF labels it otherwise.

WORKED EXAMPLE — SHARED PROCEDURE + DATASET + SUB-QUESTIONS
============================================================
PDF (abbreviated): "Questions 1 to 3. What will A, B, C be after the procedure on Scores dataset?" followed by a Step 1..Step 8 procedure, the dataset table, then Q1 asks value of A, Q2 of B, Q3 of C, with Answer Key: 6, 15, 8.

Output:
{
  "schema_version": "1.1",
  "quiz_title": "Computational Thinking — Week 1",
  "subject": "Computational Thinking",
  "questions": [
    {
      "type": "comprehension",
      "stem": "Questions 1 to 3. What will values of A, B, and C be after the execution of the following procedure using the \"Scores\" dataset?",
      "stem_code": "Step 1: Arrange all cards in a single pile called Pile 1\nStep 2: Initialize variables A, B, and C to 0\nStep 3: If Pile 1 is empty then stop the iteration\nStep 4: Read the top card in Pile 1\nStep 5: If Total > 250 then increment A\nStep 6: If Total > 200 and Total < 250 then increment B\nStep 7: If Total < 200 then increment C\nStep 8: Move the current card to another pile called Pile 2 and repeat from Step 3",
      "stem_code_language": "pseudocode",
      "stem_table": {
        "caption": "Scores Dataset",
        "headers": ["CardNo","Name","Gender","DateOfBirth","CityTown","Mathematics","Physics","Chemistry","Total"],
        "rows": [
          ["0","Bhuvanesh","M","7 Nov","Erode","68","64","78","210"],
          ["1","Harish","M","3 Jun","Salem","62","45","91","198"]
        ]
      },
      "marks": 0,
      "difficulty": "medium"
    },
    {
      "type": "numerical",
      "stem": "The value of A is ?",
      "numerical_answer": 6,
      "numerical_tolerance": 0,
      "marks": 1,
      "difficulty": "medium"
    },
    {
      "type": "numerical",
      "stem": "The value of B is ?",
      "numerical_answer": 15,
      "numerical_tolerance": 0,
      "marks": 1,
      "difficulty": "medium"
    },
    {
      "type": "numerical",
      "stem": "The value of C is ?",
      "numerical_answer": 8,
      "numerical_tolerance": 0,
      "marks": 1,
      "difficulty": "medium"
    }
  ]
}

WORKED EXAMPLE — MCQ WITH MATH
===============================
PDF: "An urn contains 3 balls numbered 1, 2 and 3. The coefficients of equation px^2 + qx + c = 0 are determined by drawing the numbered balls with replacement. What is the probability that the equation will have imaginary roots? (a) 4/27 (b) 23/27 (c) 16/27 (d) None of the above. Answer: 16/27"

Output:
{
  "type": "mcq",
  "stem": "An urn contains 3 balls numbered 1, 2 and 3. The coefficients of equation $px^2 + qx + c = 0$ are determined by drawing the numbered balls with replacement. What is the probability that the equation will have imaginary roots?",
  "options": ["$\\frac{4}{27}$", "$\\frac{23}{27}$", "$\\frac{16}{27}$", "None of the above"],
  "correct_answer": 2,
  "marks": 2,
  "difficulty": "medium"
}

WORKED EXAMPLE — MULTI-SELECT WITH MATH
========================================
{
  "type": "multi_select",
  "stem": "Let $A$ and $B$ be two mutually exclusive events in a sample space, with $P(A) > 0$, $P(B) > 0$ and $P(A)+P(B) \\leq 1$. Which of the following statement(s) is(are) incorrect?",
  "options": [
    "$P(A \\cup B) = P(A) + P(B)$",
    "$P(A \\cap B^c) = P(A)$",
    "$P(A \\cap B) = P(A) \\cdot P(B)$",
    "$P(A) \\leq P(B^c)$"
  ],
  "correct_answers": [2],
  "marks": 3,
  "difficulty": "medium"
}

WORKED EXAMPLE — STANDALONE NUMERICAL
======================================
{
  "type": "numerical",
  "stem": "If $P(A) = 0.14$ and $P(B) = 0.2$ and probability of the complement of $A \\cup B$ is 0.66, then calculate $P(A \\cup B)$. (Enter the answer correct to 2 decimal point accuracy)",
  "numerical_answer": 0.34,
  "numerical_tolerance": 0.01,
  "marks": 1,
  "difficulty": "easy"
}

CRITICAL RULE: ZERO HALLUCINATION — OPTIONS AND ANSWERS MUST BE VERBATIM
=========================================================================
Every option string MUST be copied CHARACTER-FOR-CHARACTER from the PDF. You are a transcription engine, not a paraphrasing engine. Do NOT:
- Reword, summarize, or "improve" any option text.
- Swap "less than" ↔ "more than", change variable names, change subject names, or alter any comparison.
- Invent an option that is not in the PDF.
- Merge two options into one or split one option into two.
The number of options in your output MUST match the number of options shown in the PDF for that question.

If option text in the PDF is long (wraps across lines), join the continuation into one string — do NOT split it into two options.

FINAL CHECKS BEFORE RETURNING
==============================
- Output is a single JSON object — nothing else.
- Every question has a "type" from the supported list.
- Tables are in "stem_table" with proper headers/rows arrays (NOT flattened to text).
- Procedures/pseudocode are in "stem_code" (NOT pasted into stem). stem_code never contains bare line numbers only.
- Math is wrapped in $...$ everywhere it appears.
- Shared context is split into ONE comprehension question followed by independent sub-questions.
- Answers from the answer key are filled in the correct field for the question type.
- Question order matches the PDF order.
- Options are verbatim from the PDF — count, wording, and direction (less/more/equal) are preserved exactly.
- Every question that has a "Solution:" / "Explanation:" / "Hint:" block in the PDF has a populated "explanation" field with multi-line structure preserved (\n / \n\n) and all math wrapped in LaTeX ($...$ inline, $$...$$ display). No collapsed-into-one-line explanations.
- Stems with numbered sub-items, labeled cases (Pair 1 / Pair 2 / Case A), "Hint:", "Given:", "Note:", or display equations use "\n" / "\n\n" so the structure is visible — not run together as a single wall of text.
- Bold (**...**) is applied to structural labels (Pair 1:, Step 1:, Hint:, Given:, Solution:, Therefore, Hence), critical conditional words (at least, exactly, NOT, must, only), and key technical terms being introduced. Math already inside $...$ is NOT additionally bolded with **.
PROMPT;

    public function generateFromText(string $pdfText): array
    {
        $apiKey = config('services.deepseek.key');
        if (! $apiKey) {
            throw new DeepSeekGenerationException('DeepSeek API key is not configured.');
        }

        $trimmed = trim($pdfText);
        if ($trimmed === '') {
            throw new DeepSeekGenerationException('Extracted PDF text is empty.');
        }

        $lastError = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                return $this->callApiWithText($trimmed, $apiKey);
            } catch (DeepSeekGenerationException $e) {
                $lastError = $e;
                Log::warning('DeepSeek quiz generation attempt failed', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw $lastError ?? new DeepSeekGenerationException('DeepSeek generation failed after retry.');
    }

    private function callApiWithText(string $pdfText, string $apiKey): array
    {
        $endpoint = config('services.deepseek.endpoint', 'https://api.deepseek.com/chat/completions');
        $model = config('services.deepseek.model', 'deepseek-chat');

        $response = Http::withToken($apiKey)
            ->timeout(300)
            ->connectTimeout(30)
            ->acceptJson()
            ->asJson()
            ->post($endpoint, [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => "EXTRACTED PDF TEXT:\n\n".$pdfText],
                ],
                'max_tokens' => 16000,
                'temperature' => 0.2,
                'response_format' => ['type' => 'json_object'],
            ]);

        if (! $response->successful()) {
            throw new DeepSeekGenerationException(
                "DeepSeek API error: HTTP {$response->status()} {$response->body()}"
            );
        }

        $finishReason = $response->json('choices.0.finish_reason');
        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new DeepSeekGenerationException('DeepSeek returned empty content.');
        }

        if ($finishReason === 'length') {
            Log::warning('DeepSeek response truncated by max_tokens', [
                'preview_tail' => mb_substr($content, -400),
            ]);
            throw new DeepSeekGenerationException(
                'DeepSeek response was truncated (hit the output token limit). The PDF has too many large questions for a single request — try a smaller PDF, or split it into parts.'
            );
        }

        $jsonString = $this->stripCodeFences($content);
        $decoded = json_decode($jsonString, true);

        if (! is_array($decoded)) {
            Log::warning('DeepSeek returned unparseable content', [
                'preview' => mb_substr($jsonString, 0, 500),
                'json_error' => json_last_error_msg(),
            ]);
            throw new DeepSeekGenerationException('DeepSeek response is not valid JSON.');
        }

        $this->validateEnvelope($decoded);

        return $decoded;
    }

    private function stripCodeFences(string $content): string
    {
        $trimmed = trim($content);
        if (! str_starts_with($trimmed, '```')) {
            return $trimmed;
        }

        $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed);
        $trimmed = preg_replace('/```\s*$/', '', (string) $trimmed);

        return trim((string) $trimmed);
    }

    private function validateEnvelope(array $decoded): void
    {
        if (! isset($decoded['schema_version']) || ! is_string($decoded['schema_version']) || trim($decoded['schema_version']) === '') {
            throw new DeepSeekGenerationException('Generated JSON is missing schema_version.');
        }

        $title = $decoded['quiz_title'] ?? null;
        if (! is_string($title) || trim($title) === '') {
            throw new DeepSeekGenerationException('Generated JSON is missing quiz_title.');
        }

        $subject = $decoded['subject'] ?? null;
        if (! is_string($subject) || trim($subject) === '') {
            throw new DeepSeekGenerationException('Generated JSON is missing subject.');
        }

        if (! isset($decoded['questions']) || ! is_array($decoded['questions']) || empty($decoded['questions'])) {
            throw new DeepSeekGenerationException('Generated JSON has no questions.');
        }
    }
}
