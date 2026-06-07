<?php

namespace App\Services;

use OpenAI\Laravel\Facades\OpenAI;
use App\Models\Interview;
use App\Models\Question;
use Illuminate\Support\Collection;

class LLMService
{
    /**
     * Generate interview questions based on position, skills, and experience
     */
    public function generateQuestions(Interview $interview)
    {
        $prompt = $this->buildQuestionGenerationPrompt($interview);

        try {
            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert technical interviewer. Generate relevant interview questions with evaluation criteria.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.7,
                'response_format' => ['type' => 'json_object'],
            ]);

            $content = $response->choices[0]->message->content;
            $questions = json_decode($content, true);
            return $this->validateAndFormatQuestions($questions, $interview->number_of_questions);
        } catch (\Exception $e) {
            logger($e->getMessage());
            // Fallback questions if AI fails
            return $this->getFallbackQuestions($interview);
        }
    }



//new anas & mohammed

    /**
     * Generate questions from custom prompt
     */
    public function generateQuestionsFromPrompt(string $prompt, int $expectedCount): array
    {
        try {
            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert technical interviewer. Generate relevant practice questions.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.7,
                'response_format' => ['type' => 'json_object'],
            ]);

            $content = $response->choices[0]->message->content;
            $data = json_decode($content, true);

            if (!isset($data['questions']) || count($data['questions']) !== $expectedCount) {
                return $this->getFallbackQuestionsFromPrompt($expectedCount);
            }

            return $data;
        } catch (\Exception $e) {
            return $this->getFallbackQuestionsFromPrompt($expectedCount);
        }
    }

    /**
     * Fallback for prompt-based questions
     */
    private function getFallbackQuestionsFromPrompt(int $count): array
    {
        $questions = [];
        $templates = [
            "Tell me about a challenging technical problem you solved recently.",
            "How do you stay updated with the latest technologies?",
            "Describe your experience working in a team environment.",
            "What's your approach to writing clean, maintainable code?",
            "How do you handle constructive criticism?"
        ];

        for ($i = 0; $i < $count; $i++) {
            $questions[] = [
                'question_text' => $templates[$i % count($templates)],
                'type' => $i % 2 == 0 ? 'behavioral' : 'technical',
                'focus_area' => 'general'
            ];
        }

        return ['questions' => $questions];
    }



    //new anas & mohammed 2


    /**
 * Analyze resume using AI
 */
public function analyzeResume(string $extractedText, ?string $targetPosition = null, ?array $targetSkills = null): array
{
    $positionText = $targetPosition
        ? "Target position: {$targetPosition}\n"
        : "No specific target position provided.\n";

    $skillsText = $targetSkills
        ? "Target skills to highlight: " . implode(', ', $targetSkills) . "\n"
        : "No specific target skills provided.\n";

    $prompt = <<<EOT
You are an expert resume reviewer and career coach. Analyze the following resume and provide detailed feedback.

{$positionText}
{$skillsText}

RESUME TEXT:
{$extractedText}

Analyze the resume and return a JSON response with the following structure:

{
    "ats_score": 0-100 (how well this resume would perform with ATS systems),
    "strengths": ["list of 3-5 strengths of this resume"],
    "weaknesses": ["list of 3-5 weaknesses or missing elements"],
    "suggestions": ["list of 3-5 specific suggestions for improvement"],
    "missing_skills": ["skills mentioned in target position but missing from resume"],
    "formatting_issues": ["any formatting or readability issues"],
    "overall_assessment": "brief 1-2 sentence overall evaluation",
    "keyword_optimization": "suggestions for keyword optimization"
}

Be specific, constructive, and actionable.
EOT;

    try {
        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an expert resume reviewer. Provide detailed, constructive feedback.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.5,
            'response_format' => ['type' => 'json_object'],
        ]);

        $content = $response->choices[0]->message->content;
        return json_decode($content, true);

    } catch (\Exception $e) {
        return $this->getFallbackResumeAnalysis($extractedText);
    }
}

/**
 * Generate improved resume content
 */
public function improveResume(string $extractedText, array $analysis, ?string $targetPosition = null): array
{
    $positionText = $targetPosition
        ? "Improve this resume for a {$targetPosition} position.\n"
        : "Improve this resume for better ATS compatibility.\n";

    $weaknessesText = isset($analysis['weaknesses'])
        ? "Focus on fixing these issues: " . implode(', ', array_slice($analysis['weaknesses'], 0, 3)) . "\n"
        : "";

    $prompt = <<<EOT
{$positionText}
{$weaknessesText}

ORIGINAL RESUME:
{$extractedText}

Return a JSON response with:
{
    "improved_content": "the improved resume text with better formatting and keyword optimization",
    "changes_summary": "brief summary of key changes made",
    "added_keywords": ["keywords that were added"],
    "sections_to_rework": ["sections that need more work"]
}
EOT;

    try {
        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an expert resume writer. Improve resumes for better ATS performance.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.6,
            'response_format' => ['type' => 'json_object'],
        ]);

        $content = $response->choices[0]->message->content;
        return json_decode($content, true);

    } catch (\Exception $e) {
        return [
            'improved_content' => $extractedText,
            'changes_summary' => 'Unable to generate improvements at this time',
            'added_keywords' => [],
            'sections_to_rework' => [],
        ];
    }
}

/**
 * Fallback analysis if AI fails
 */
private function getFallbackResumeAnalysis(string $text): array
{
    $length = strlen($text);
    $hasContact = preg_match('/\b(?:email|phone|\+?\d|\@)\b/i', $text);

    return [
        'ats_score' => $length > 500 ? 65 : 45,
        'strengths' => ['Resume submitted for review'],
        'weaknesses' => ['Unable to perform full AI analysis. Please try again.'],
        'suggestions' => ['Ensure your resume includes clear section headers', 'Add quantifiable achievements', 'Use industry-standard keywords'],
        'missing_skills' => [],
        'formatting_issues' => $hasContact ? [] : ['Missing contact information may be incomplete'],
        'overall_assessment' => 'Manual review recommended due to analysis limitations',
        'keyword_optimization' => 'Review job descriptions and align your resume keywords',
    ];
}

//--------------------------------------------------



    /**
     * Generate final comprehensive report
     */
    public function generateFinalReport(
        Interview $interview,
        Collection $answers,
        Collection $evaluations,
        array $violationSummary,
        float $cheatingSeverityScore
    ): array {
        $prompt = $this->buildFinalReportPrompt(
            $interview,
            $answers,
            $evaluations,
            $violationSummary,
            $cheatingSeverityScore
        );

        try {
            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert hiring manager and interview evaluator. Provide comprehensive, fair, and constructive feedback. IMPORTANT: Apply penalties for any detected cheating behavior.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.5,
                'response_format' => ['type' => 'json_object'],
                'max_tokens' => 2000,
            ]);

            $content = $response->choices[0]->message->content;
            $report = json_decode($content, true);

            return $this->validateAndEnrichReport($report, $evaluations, $cheatingSeverityScore);
        } catch (\Exception $e) {
            // Fallback report generation
            return $this->generateFallbackReport($interview, $evaluations, $cheatingSeverityScore);
        }
    }

    /**
     * Build prompt for question generation
     */
    private function buildQuestionGenerationPrompt(Interview $interview): string
    {

        $skillsList = implode(', ', $interview->skills);

        return <<<EOT
Generate {$interview->number_of_questions} interview questions for a {$interview->experience_level} level {$interview->position} position.

Required skills: {$skillsList}
Difficulty level: {$interview->difficulty}

Format the response as a JSON object with a 'questions' array. Each question should have:
- question_text: The actual question
- type: One of ['technical', 'behavioral', 'situational', 'general']
- expected_skills: Array of skills this question evaluates
- evaluation_criteria: Array of key points to evaluate in the answer
- order: Question number (1-based)

Ensure a mix of question types and cover the required skills appropriately.
EOT;
    }

    /**
     * Build comprehensive prompt for final report
     */
    private function buildFinalReportPrompt(
        Interview $interview,
        Collection $answers,
        Collection $evaluations,
        array $violationSummary,
        float $cheatingSeverityScore
    ): string {
        $answersData = [];
        foreach ($answers as $index => $answer) {
            $question = $answer->question;
            $evaluation = $evaluations->where('answer_id', $answer->id)->first();

            $answersData[] = [
                'question_number' => $index + 1,
                'question' => $question->question_text,
                'type' => $question->type,
                'answer_transcript' => $answer->transcription,
                'score' => $evaluation ? $evaluation->score : 0,
                'strengths' => $evaluation ? $evaluation->strengths : '',
                'weaknesses' => $evaluation ? $evaluation->weaknesses : '',
                'audio_metrics' => $answer->audioAnalysis ? [
                    'speaking_rate' => $answer->audioAnalysis->speaking_rate,
                    'filler_words' => $answer->audioAnalysis->filler_word_count,
                    'confidence' => $answer->audioAnalysis->confidence_level,
                ] : null,
            ];
        }

        $violationContext = '';
        if ($cheatingSeverityScore > 0) {
            $violationContext = <<<EOT

CHEATING DETECTION SUMMARY:
Severity Score: {$cheatingSeverityScore}/10
Total Violations: {$violationSummary['total_violations']}
Violation Types:
EOT;

            foreach ($violationSummary['by_type'] as $violation) {
                $violationContext .= "\n- {$violation['violation_type']}: {$violation['count']} occurrences, {$violation['total_duration']}s total duration";
            }

            $violationContext .= "\n\nIMPORTANT: You MUST apply appropriate score penalties for detected cheating. The severity score of {$cheatingSeverityScore}/10 should result in a proportional score reduction. Candidates who cheat should receive lower overall evaluations, especially in integrity-related areas.";
        }

        $answersJson = json_encode($answersData, JSON_PRETTY_PRINT);

        return <<<EOT
Generate a comprehensive final interview report based on the following data:

INTERVIEW DETAILS:
Position: {$interview->position}
Experience Level: {$interview->experience_level}
Skills Required: {$answersJson}
Number of Questions: {$interview->number_of_questions}

ANSWERS AND EVALUATIONS:
{$answersJson}
{$violationContext}

Generate a JSON response with the following structure:
{
    "executive_summary": "Brief 2-3 sentence overview",
    "overall_score": 0-10,
    "adjusted_score": 0-10 (after cheating penalty),
    "technical_score": 0-10,
    "communication_score": 0-10,
    "problem_solving_score": 0-10,
    "strengths_analysis": "Detailed analysis of strengths",
    "improvement_areas": "Specific areas for improvement",
    "skill_breakdown": {
        "skill_name": score (0-10)
    },
    "question_evaluations_summary": "Overall performance across questions",
    "hiring_recommendation": "One of: 'Strongly Recommend', 'Recommend', 'Consider', 'Do Not Recommend' with reasoning"
}

Ensure all scores reflect the cheating severity score if violations were detected. The adjusted_score should be lower than overall_score if cheating was detected.
EOT;
    }

    /**
     * Validate and format AI-generated questions
     */
    private function validateAndFormatQuestions(array $data, int $expectedCount): array
    {
        if (!isset($data['questions']) || count($data['questions']) !== $expectedCount) {
            return $this->getFallbackQuestions(Interview::find(1));
        }

        $formatted = [];
        foreach ($data['questions'] as $index => $question) {
            $formatted[] = [
                'question_text' => $question['question_text'] ?? 'Please describe your experience with relevant technologies.',
                'type' => in_array($question['type'] ?? '', ['technical', 'behavioral', 'situational', 'general'])
                    ? $question['type']
                    : 'general',
                'expected_skills' => $question['expected_skills'] ?? [],
                'evaluation_criteria' => $question['evaluation_criteria'] ?? ['clarity', 'depth', 'relevance'],
                'order' => $index + 1,
            ];
        }

        return $formatted;
    }

    /**
     * Validate and enrich AI-generated report
     */
    private function validateAndEnrichReport(array $report, Collection $evaluations, float $cheatingSeverityScore): array
    {
        // Calculate average scores from evaluations
        $avgScore = $evaluations->avg('score') ?? 0;

        // Apply cheating penalty to adjusted score
        $penaltyMultiplier = max(0, 1 - ($cheatingSeverityScore / 20)); // Max 50% penalty

        return [
            'executive_summary' => $report['executive_summary'] ?? 'Interview completed successfully.',
            'overall_score' => round($avgScore, 2),
            'adjusted_score' => round($avgScore * $penaltyMultiplier, 2),
            'technical_score' => $report['technical_score'] ?? $avgScore,
            'communication_score' => $report['communication_score'] ?? $avgScore,
            'problem_solving_score' => $report['problem_solving_score'] ?? $avgScore,
            'strengths_analysis' => $report['strengths_analysis'] ?? 'Demonstrated understanding of core concepts.',
            'improvement_areas' => $report['improvement_areas'] ?? 'Continue developing technical depth.',
            'skill_breakdown' => $report['skill_breakdown'] ?? ['general' => $avgScore],
            'question_evaluations' => $evaluations->map(fn($e) => [
                'question_id' => $e->question_id,
                'score' => $e->score,
                'feedback' => $e->detailed_feedback
            ])->toArray(),
            'hiring_recommendation' => $report['hiring_recommendation'] ?? ($avgScore >= 7 ? 'Recommend' : 'Consider'),
            'ai_raw_response' => $report,
        ];
    }

    /**
     * Fallback questions if AI fails
     */
    private function getFallbackQuestions(Interview $interview): array
    {
        $questions = [];
        $skill = $interview->skills[0] ?? 'software development';

        $templates = [
            "Tell me about your experience with {$skill}.",
            "What's the most challenging {$skill} project you've worked on?",
            "How do you stay updated with the latest developments in {$skill}?",
            "Describe a time you had to learn a new technology quickly.",
            "How do you approach problem-solving in {$skill}?"
        ];

        foreach (array_slice($templates, 0, $interview->number_of_questions) as $index => $template) {
            $questions[] = [
                'question_text' => $template,
                'type' => $index < 2 ? 'technical' : 'behavioral',
                'expected_skills' => [$skill],
                'evaluation_criteria' => ['clarity', 'depth', 'relevance', 'confidence'],
                'order' => $index + 1,
            ];
        }

        return $questions;
    }

    /**
     * Fallback report generation
     */
    private function generateFallbackReport(
        Interview $interview,
        Collection $evaluations,
        float $cheatingSeverityScore
    ): array {
        $avgScore = $evaluations->avg('score') ?? 0;
        $penaltyMultiplier = max(0, 1 - ($cheatingSeverityScore / 20));

        return [
            'executive_summary' => "The candidate completed the interview for {$interview->position} position.",
            'overall_score' => round($avgScore, 2),
            'adjusted_score' => round($avgScore * $penaltyMultiplier, 2),
            'technical_score' => round($avgScore, 2),
            'communication_score' => round($avgScore, 2),
            'problem_solving_score' => round($avgScore, 2),
            'strengths_analysis' => 'Demonstrated understanding of key concepts.',
            'improvement_areas' => 'Consider deepening technical knowledge.',
            'skill_breakdown' => array_fill_keys($interview->skills, $avgScore),
            'question_evaluations' => $evaluations->map(fn($e) => [
                'question_id' => $e->question_id,
                'score' => $e->score,
                'feedback' => $e->detailed_feedback ?? 'Answer evaluated.'
            ])->toArray(),
            'hiring_recommendation' => $avgScore >= 7 ? 'Recommend' : 'Consider',
            'ai_raw_response' => ['note' => 'Fallback response generated'],
        ];
    }
}
