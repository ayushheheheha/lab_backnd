<?php

namespace App\Services;

use App\Exceptions\DeepSeekGenerationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeepSeekQuizGenerator
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You will be given the extracted text of a question-paper PDF. The PDF already contains the full questions — with their options, code blocks, true/false statements, numerical answers, and anything else needed. Your job is to convert those existing questions, verbatim, into the bulk-quiz JSON payload defined by the template below.

DO NOT invent new questions. DO NOT rewrite, paraphrase, summarize, or "improve" the questions. Preserve the question text, options, code, and correct answers exactly as they appear in the PDF. Only restructure them into the required JSON shape.

Read the template carefully — it contains template_info (rules), generic_format (skeleton), and example_payload (style reference). Return ONLY the final payload shape (schema_version + quiz_title + subject + questions), with values taken from the PDF. Do NOT wrap your output in markdown, backticks, or commentary. Do NOT include template_info, generic_format, or example_payload in your output. Return raw JSON only.

Detect the question type from how each question is written (mcq_single / mcq_multi / true_false / short_answer / numerical) and use the matching fields. If the PDF includes code, preserve it inside a "code" block. If a question explicitly marks more than one correct option, use mcq_multi. Infer quiz_title and subject from the PDF header / topic if present; otherwise pick a short descriptive title.

make sure to have the exact same question as you recieve, no need to change anything in the question by yourself, you have to look in the question if the question has pseudocode, or any code or text with steps so put it in its particular block according to the json format i have provided you, make sure you have the exact same question as you get adn for the answers you can look at the answers key at the last if it is provided

if the question has dataset or table, or like any infor in the form of rowns wnd columns then i am giving you the json example for entering that table too,

so you have too see if the ques has any code block pseudocode smth, or it has table or both, add them

now there are some questions taht are comprehension type for example you first see a comprehension then you see the subquestions of the comprehension, so what you have to do is , only display the sub questions with the exact data and information given in the comprehension first

fir eg u see ques 1-3 comprehension, then u see ques 1 , 2 , 3  , now use the data of the comprehension in all three quesions 1,2,3, use the exact same sub question

{
  "schema_version": "1.0",
  "questions": [
    {
      "type": "mcq",
      "stem": "Based on the operations table below, which one should you avoid in a hot path that runs once per request?",
      "stem_table": {
        "caption": "Time complexity on a sorted array",
        "headers": ["Operation", "Worst Case", "Best Case"],
        "rows": [
          ["Binary Search",      "O(log n)", "O(1)"],
          ["Linear Scan",        "O(n)",     "O(1)"],
          ["Insert (in-place)",  "O(n)",     "O(n)"],
          ["Append to end",      "O(1)",     "O(1)"]
        ]
      },
      "options": [
        "Binary Search",
        "Linear Scan",
        "Insert (in-place)",
        "Append to end"
      ],
      "correct_answer": 2,
      "marks": 2,
      "difficulty": "medium",
      "explanation": "Insert-in-place is O(n) in both best and worst case, so it is the worst choice for a hot per-request path."
    }
  ]
}

MATHEMATICAL NOTATION — THIS IS MANDATORY:
Any mathematical expression in the PDF — fractions, summations, integrals, exponents, subscripts, Greek letters, set notation, probability notation, combinatorics, or any formula — MUST be written using LaTeX enclosed in dollar signs inside the "value" string.
- Inline math (within a sentence): $\frac{n!}{k!(n-k)!}$
- Display/block math (on its own line): $$\sum_{i=1}^{n} i = \frac{n(n+1)}{2}$$
NEVER write bare LaTeX commands without wrapping them in $...$. NEVER write math as plain text like "n!/k!(n-k)!" — always use LaTeX dollar-sign notation. Preserve ALL mathematical expressions exactly as they appear in the PDF, converting symbolic notation to LaTeX if needed.

TEMPLATE:

{
  "template_info": {
    "purpose": "Give this file to AI to convert question papers into the required JSON for bulk quiz import.",
    "how_to_use": [
      "AI should follow generic_format for structure and required keys.",
      "AI should use example_payload as style reference.",
      "After AI conversion, copy only example_payload-like output for final import (schema_version + quiz details + questions)."
    ],
    "supported_question_types": ["mcq_single", "mcq_multi", "true_false", "short_answer", "numerical"],
    "supported_block_kinds": ["text", "code", "image"]
  },
  "generic_format": {
    "schema_version": "1.1",
    "quiz_title": "<quiz title>",
    "subject": "<subject name>",
    "questions": [
      {
        "type": "mcq_single",
        "prompt": [
          { "kind": "text", "value": "<question text>" },
          { "kind": "code", "language": "python", "value": "<optional code block>" }
        ],
        "options": [
          [{ "kind": "text", "value": "<option 1>" }],
          [{ "kind": "text", "value": "<option 2>" }],
          [{ "kind": "text", "value": "<option 3>" }],
          [{ "kind": "text", "value": "<option 4>" }]
        ],
        "correct_answer": 0,
        "marks": 1,
        "difficulty": "easy",
        "explanation": [
          { "kind": "text", "value": "<optional explanation>" }
        ]
      },
      {
        "type": "mcq_multi",
        "prompt": [
          { "kind": "text", "value": "<question text>" }
        ],
        "options": [
          [{ "kind": "text", "value": "<option 1>" }],
          [{ "kind": "text", "value": "<option 2>" }],
          [{ "kind": "code", "language": "python", "value": "<option 3 code>" }],
          [{ "kind": "text", "value": "<option 4>" }]
        ],
        "correct_answers": [0, 2],
        "marks": 2,
        "difficulty": "medium"
      },
      {
        "type": "true_false",
        "prompt": [
          { "kind": "text", "value": "<statement>" }
        ],
        "correct_answer": true,
        "marks": 1,
        "difficulty": "easy"
      },
      {
        "type": "short_answer",
        "prompt": [
          { "kind": "text", "value": "<short answer question>" },
          { "kind": "image", "asset": "<optional-image-file.png>", "alt": "<optional alt text>" }
        ],
        "acceptable_answers": ["<answer1>", "<answer2>"],
        "marks": 2,
        "difficulty": "easy"
      },
      {
        "type": "numerical",
        "prompt": [
          { "kind": "text", "value": "<numerical question>" },
          { "kind": "code", "language": "python", "value": "<optional code block>" }
        ],
        "numerical_answer": 42,
        "numerical_tolerance": 0,
        "marks": 2,
        "difficulty": "medium"
      }
    ]
  },
  "example_payload": {
    "schema_version": "1.1",
    "quiz_title": "Python Fundamentals Mixed Quiz",
    "subject": "Python",
    "questions": [
      {
        "type": "mcq_single",
        "prompt": [
          { "kind": "text", "value": "What is the output of this code?" },
          { "kind": "code", "language": "python", "value": "x = [1, 2, 3]\nprint(x[::-1])" }
        ],
        "options": [
          [{ "kind": "text", "value": "[1, 2, 3]" }],
          [{ "kind": "text", "value": "[3, 2, 1]" }],
          [{ "kind": "text", "value": "(3, 2, 1)" }],
          [{ "kind": "text", "value": "Error" }]
        ],
        "correct_answer": 1,
        "marks": 2,
        "difficulty": "easy",
        "explanation": [
          { "kind": "text", "value": "Negative step slices the list in reverse order." }
        ]
      },
      {
        "type": "mcq_multi",
        "prompt": [
          { "kind": "text", "value": "Which snippets correctly define a Python dictionary?" },
          { "kind": "code", "language": "python", "value": "# Choose all valid dictionary declarations" }
        ],
        "options": [
          [{ "kind": "code", "language": "python", "value": "d = {'a': 1, 'b': 2}" }],
          [{ "kind": "code", "language": "python", "value": "d = dict(a=1, b=2)" }],
          [{ "kind": "code", "language": "python", "value": "d = [('a', 1), ('b', 2)]" }],
          [{ "kind": "code", "language": "python", "value": "d = {a: 1, b: 2}" }]
        ],
        "correct_answers": [0, 1],
        "marks": 3,
        "difficulty": "medium",
        "explanation": [
          { "kind": "text", "value": "Options 1 and 2 are valid dictionary creations. Option 3 is a list; option 4 raises NameError unless a and b are defined." }
        ]
      },
      {
        "type": "true_false",
        "prompt": [
          { "kind": "text", "value": "In Python, list comprehensions can include an if condition." },
          { "kind": "code", "language": "python", "value": "evens = [n for n in range(10) if n % 2 == 0]" }
        ],
        "correct_answer": true,
        "marks": 1,
        "difficulty": "easy",
        "explanation": [
          { "kind": "text", "value": "The if clause filters elements in the comprehension." }
        ]
      },
      {
        "type": "short_answer",
        "prompt": [
          { "kind": "text", "value": "Name the built-in Python function used to get the number of items in a list." },
          { "kind": "image", "asset": "python-list-memory-diagram.png", "alt": "Illustration of list elements in memory" }
        ],
        "acceptable_answers": ["len", "len()"],
        "marks": 2,
        "difficulty": "easy",
        "explanation": [
          { "kind": "text", "value": "len(obj) returns the number of elements in containers like lists, tuples, sets, and strings." }
        ]
      },
      {
        "type": "numerical",
        "prompt": [
          { "kind": "text", "value": "What value will be printed?" },
          { "kind": "code", "language": "python", "value": "total = 0\nfor i in range(1, 6):\n    total += i\nprint(total)" }
        ],
        "numerical_answer": 15,
        "numerical_tolerance": 0,
        "marks": 2,
        "difficulty": "medium",
        "explanation": [
          { "kind": "text", "value": "range(1, 6) includes 1 to 5, and their sum is 15." }
        ]
      }
    ]
  },
  "schema_version": "1.1",
  "quiz_title": "Python Fundamentals Mixed Quiz",
  "subject": "Python",
  "questions": [
    {
      "type": "mcq_single",
      "prompt": [
        { "kind": "text", "value": "What is the output of this code?" },
        { "kind": "code", "language": "python", "value": "x = [1, 2, 3]\nprint(x[::-1])" }
      ],
      "options": [
        [{ "kind": "text", "value": "[1, 2, 3]" }],
        [{ "kind": "text", "value": "[3, 2, 1]" }],
        [{ "kind": "text", "value": "(3, 2, 1)" }],
        [{ "kind": "text", "value": "Error" }]
      ],
      "correct_answer": 1,
      "marks": 2,
      "difficulty": "easy",
      "explanation": [
        { "kind": "text", "value": "Negative step slices the list in reverse order." }
      ]
    },
    {
      "type": "mcq_multi",
      "prompt": [
        { "kind": "text", "value": "Which snippets correctly define a Python dictionary?" },
        { "kind": "code", "language": "python", "value": "# Choose all valid dictionary declarations" }
      ],
      "options": [
        [{ "kind": "code", "language": "python", "value": "d = {'a': 1, 'b': 2}" }],
        [{ "kind": "code", "language": "python", "value": "d = dict(a=1, b=2)" }],
        [{ "kind": "code", "language": "python", "value": "d = [('a', 1), ('b', 2)]" }],
        [{ "kind": "code", "language": "python", "value": "d = {a: 1, b: 2}" }]
      ],
      "correct_answers": [0, 1],
      "marks": 3,
      "difficulty": "medium"
    },
    {
      "type": "true_false",
      "prompt": [
        { "kind": "text", "value": "In Python, list comprehensions can include an if condition." },
        { "kind": "code", "language": "python", "value": "evens = [n for n in range(10) if n % 2 == 0]" }
      ],
      "correct_answer": true,
      "marks": 1,
      "difficulty": "easy"
    },
    {
      "type": "short_answer",
      "prompt": [
        { "kind": "text", "value": "Name the built-in Python function used to get the number of items in a list." },
        { "kind": "image", "asset": "python-list-memory-diagram.png", "alt": "Illustration of list elements in memory" }
      ],
      "acceptable_answers": ["len", "len()"],
      "marks": 2,
      "difficulty": "easy"
    },
    {
      "type": "numerical",
      "prompt": [
        { "kind": "text", "value": "What value will be printed?" },
        { "kind": "code", "language": "python", "value": "total = 0\nfor i in range(1, 6):\n    total += i\nprint(total)" }
      ],
      "numerical_answer": 15,
      "numerical_tolerance": 0,
      "marks": 2,
      "difficulty": "medium"
    }
  ]
}
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

        // Cap input to keep request size reasonable. DeepSeek context allows much more,
        // but extremely long inputs degrade quality and inflate cost.
        if (mb_strlen($trimmed) > 40000) {
            $trimmed = mb_substr($trimmed, 0, 40000);
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
            ->timeout(150)
            ->acceptJson()
            ->asJson()
            ->post($endpoint, [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => "PDF CONTENT:\n\n".$pdfText],
                ],
                'max_tokens' => 16000,
                'temperature' => 0.4,
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
