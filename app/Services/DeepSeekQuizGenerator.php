<?php

namespace App\Services;

use App\Exceptions\DeepSeekGenerationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeepSeekQuizGenerator
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You convert the extracted text of a question-paper PDF into a strict JSON payload for bulk quiz import. The PDF already contains complete questions, options, code/pseudocode, tables/datasets, and (usually) an answer key. Your job is to FAITHFULLY restructure them into the JSON shape below — never invent, rewrite, paraphrase, "improve", summarize, or fix anything in the questions. Preserve the original wording exactly.

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
  "explanation": "<optional, only if PDF shows a worked solution / reasoning>"
}

Type-specific fields (add to the common shape):
- mcq:           "options": ["A","B","C","D"],  "correct_answer": <0-based index>
- multi_select:  "options": ["A","B","C","D"],  "correct_answers": [<0-based indices>]
- true_false:    "correct_answer": true | false
- short_answer:  "acceptable_answers": ["answer1","alt1","alt2"]
- numerical:     "numerical_answer": <number>,  "numerical_tolerance": <number, default 0.01>
- comprehension: (none — only the common content fields)

OPTIONS CAN BE CODE
====================
If an option is itself code (e.g. "Which snippet is valid?"), write it as an object instead of a plain string:
  { "option_type": "code", "option_text": "d = {'a': 1}", "code_language": "python" }
Otherwise keep options as plain strings.

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

FINAL CHECKS BEFORE RETURNING
==============================
- Output is a single JSON object — nothing else.
- Every question has a "type" from the supported list.
- Tables are in "stem_table" with proper headers/rows arrays (NOT flattened to text).
- Procedures/pseudocode are in "stem_code" (NOT pasted into stem).
- Math is wrapped in $...$ everywhere it appears.
- Shared context is split into ONE comprehension question followed by independent sub-questions.
- Answers from the answer key are filled in the correct field for the question type.
- Question order matches the PDF order.
PROMPT;

    public function generateFromText(string $pdfText): array
    {
        $apiKey = config('services.deepseek.key');
        if (! $apiKey) {
            throw new DeepSeekGenerationException('DeepSeek API key is not configured.');
        }

        $trimmed = trim($pdfText);
        if ($trimmed === '') {
            throw new DeepSeekGenerationException('PDF text is empty.');
        }

        $lastError = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                return $this->callApiAndValidate($trimmed, $apiKey);
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

    private function callApiAndValidate(string $pdfText, string $apiKey): array
    {
        $endpoint = config('services.deepseek.endpoint', 'https://api.deepseek.com/chat/completions');
        $model = config('services.deepseek.model', 'deepseek-chat');

        $response = Http::withToken($apiKey)
            ->timeout(300)
            ->acceptJson()
            ->asJson()
            ->post($endpoint, [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => "PDF CONTENT:\n\n".$pdfText],
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

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new DeepSeekGenerationException('DeepSeek returned empty content.');
        }

        $jsonString = $this->stripCodeFences($content);
        $decoded = json_decode($jsonString, true);

        if (! is_array($decoded)) {
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
