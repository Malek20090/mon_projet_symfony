<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @phpstan-type State array<string, mixed>
 * @phpstan-type AccPack array<string, mixed>
 * @phpstan-type Reply array{ok: bool, reply: string, source: string, model: string|null, error: string|null, state: array<string, mixed>}
 * @phpstan-type DbContext array<string, mixed>
 */
class SavingsAssistantService
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    private function env(string $name, string $default = ''): string
    {
        $value = getenv($name);
        if ($value !== false && $value !== '') {
            return (string) $value;
        }

        $fromEnv = (string) ($_ENV[$name] ?? '');
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $fromServer = (string) ($_SERVER[$name] ?? '');
        if ($fromServer !== '') {
            return $fromServer;
        }

        return $default;
    }

    private function pick(array $items): string
    {
        if (count($items) === 0) {
            return '';
        }

        return (string) $items[random_int(0, count($items) - 1)];
    }

    private function containsAny(string $text, array $needles): bool
    {
        $haystack = mb_strtolower(trim($text));
        if ($haystack === '') {
            return false;
        }

        foreach ($needles as $n) {
            $needle = mb_strtolower(trim((string) $n));
            if ($needle === '') {
                continue;
            }

            // For very short tokens (e.g. "hi"), avoid false positives inside words like "nothing" or "habits".
            if (mb_strlen($needle) <= 3 && !str_contains($needle, ' ')) {
                $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}_])/u';
                if (preg_match($pattern, $haystack) === 1) {
                    return true;
                }
                continue;
            }

            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function detectTopic(string $question): string
    {
        $q = mb_strtolower(trim($question));
        if ($q === '') {
            return 'general';
        }

        if ($this->containsAny($q, ['math', 'algebra', 'geometry', 'calculus', 'equation', 'integral', 'derivative', 'probability', 'statistics'])) {
            return 'math';
        }
        if ($this->containsAny($q, ['code', 'coding', 'programming', 'bug', 'python', 'php', 'javascript', 'java', 'symfony', 'api'])) {
            return 'coding';
        }
        if ($this->containsAny($q, ['translate', 'translation', 'language', 'english', 'french', 'arabic', 'grammar'])) {
            return 'language';
        }
        if ($this->containsAny($q, ['write', 'email', 'resume', 'cv', 'cover letter', 'essay'])) {
            return 'writing';
        }
        if ($this->containsAny($q, ['finance', 'budget', 'saving', 'goal', 'investment', 'debt', 'cashflow'])) {
            return 'finance';
        }
        if ($this->containsAny($q, ['business', 'buissness', 'startup', 'funding', 'raise money', 'seed'])) {
            return 'finance';
        }

        return 'general';
    }

    private function isFollowUpPrompt(string $question): bool
    {
        $q = mb_strtolower(trim($question));
        if ($q === '') {
            return false;
        }

        $exact = [
            'more',
            'tell me more',
            'more details',
            'details please',
            'can you expand',
            'expand',
            'continue',
            'go deeper',
        ];
        if (in_array($q, $exact, true)) {
            return true;
        }

        return false;
    }

    private function classifyQuestion(string $question): string
    {
        $q = mb_strtolower(trim($question));
        if ($q === '') {
            return 'generic';
        }

        $dbSignals = [
            'my goal', 'my goals', 'my savings', 'my balance', 'my account',
            'risk goals', 'deadline', 'contribute', 'plan for my', 'my cashflow',
            'mes objectifs', 'mes goals', 'mon epargne', 'mon solde', 'mes depenses',
            'mes revenus', 'selon mes donnees', 'selon ma base',
        ];

        $generalSignals = [
            'what is inflation', 'explain inflation', 'portfolio', 'etf', 'compound interest',
            'budgeting', 'financial freedom', 'how to invest', 'how to save money',
            'c est quoi', 'definition', 'explique', 'in general',
        ];

        $hasDb = $this->containsAny($q, $dbSignals);
        $hasGeneral = $this->containsAny($q, $generalSignals);

        if ($hasDb && $hasGeneral) {
            return 'mixed';
        }
        if ($hasDb) {
            return 'db';
        }
        return 'generic';
    }

    private function compactDbDigest(array $dbContext): array
    {
        return [
            'balanceNow' => $dbContext['balanceNow'] ?? 0,
            'remainingTotal' => $dbContext['remainingTotal'] ?? 0,
            'goalsAtRisk' => $dbContext['goalsAtRisk'] ?? 0,
            'topGoal' => $dbContext['topGoal'] ?? null,
            'net30d' => $dbContext['net30d'] ?? 0,
            'expenseByCategory30d' => $dbContext['expenseByCategory30d'] ?? [],
            'revenueByType30d' => $dbContext['revenueByType30d'] ?? [],
            'goalsCount' => $dbContext['goalsCount'] ?? 0,
            'goals' => array_slice(is_array($dbContext['goals'] ?? null) ? $dbContext['goals'] : [], 0, 10),
        ];
    }

    /** @phpstan-ignore-next-line */
    private function buildAiUnavailableReply(): string
    {
        return "AI assistant is running in local fallback mode (degraded).\n"
            . "For best natural-language understanding and reformulation, set OPENAI_API_KEY in .env.local.";
    }

    private function buildConservativeVsAggressive(array $dbContext): string
    {
        $remaining = (float) ($dbContext['remainingTotal'] ?? 0.0);
        $risk = (int) ($dbContext['goalsAtRisk'] ?? 0);
        $income30d = (float) ($dbContext['income30d'] ?? 0.0);
        $expense30d = (float) ($dbContext['expense30d'] ?? 0.0);
        $base = max(200.0, round($remaining / 12.0, 0));
        $conservative = round($base * 0.8, 0);
        $aggressive = round($base * 1.25, 0);
        $cashflow = round($income30d - $expense30d, 2);

        return "Conservative vs Aggressive plan:\n"
            . "Conservative:\n"
            . "- Target {$conservative} TND/month\n"
            . "- Priority: emergency buffer + urgent goals\n"
            . "- Lower stress, slower completion\n"
            . "Aggressive:\n"
            . "- Target {$aggressive} TND/month\n"
            . "- Priority: fastest completion + high-impact goals\n"
            . "- Faster progress, higher discipline risk\n"
            . "Current signal: risk goals {$risk}, cashflow {$cashflow} TND.\n"
            . "Recommendation: start at " . round(($conservative + $aggressive) / 2, 0) . " TND/month, review after 30 days.";
    }

    private function buildGeneralFallbackReply(string $q): string
    {
        $topic = $this->detectTopic($q);

        if ($this->containsAny($q, [
            'what are the topics you can handle',
            'what topics can you handle',
            'what can you handle',
            'what can you do',
            'which topics',
            'topics you handle',
            'help topics',
        ])) {
            return "I can handle these topics:\n"
                . "- Personal finance (budget, debt, savings, goals)\n"
                . "- Your DB-aware savings/goals data in this app\n"
                . "- Business basics (funding, validation, planning)\n"
                . "- Coding and debugging\n"
                . "- Math exercises and quizzes\n"
                . "- Writing, translation, and language help\n"
                . "- Study/exam preparation and interview practice";
        }

        if ($this->containsAny($q, ['weather', 'meteo', 'temperature', 'climate', 'news', 'breaking', 'score'])) {
            return "I can help on many topics, but I do not have live real-time data in fallback mode.\n"
                . "If you need weather/news/scores, please check a live source, or ask me for explanation and strategy.";
        }

        if ($topic === 'language' || $this->containsAny($q, ['translate', 'translation', 'traduire', 'traduction'])) {
            return "I can translate text.\nSend: source language, target language, and the sentence to translate.";
        }

        if ($topic === 'coding' || $this->containsAny($q, ['code', 'programming', 'bug', 'python', 'php', 'javascript', 'symfony'])) {
            return "I can help with code, debugging, and architecture.\n"
                . "Share the error message, expected behavior, and a short code snippet for a concrete fix.";
        }

        if ($topic === 'math' || $this->containsAny($q, ['math', 'calculate', 'equation', 'integral', 'derivative', 'probability'])) {
            return "I can solve math step-by-step.\n"
                . "Send the exact expression/problem and I will solve it clearly.";
        }

        if ($topic === 'writing' || $this->containsAny($q, ['write', 'email', 'cv', 'resume', 'motivation letter', 'cover letter'])) {
            return "I can write and improve professional text.\n"
                . "Tell me tone, audience, and length; I will draft a clean version.";
        }

        if ($this->containsAny($q, ['financial information', 'financial informations', 'finance info', 'learn finance', 'education', 'inflation', 'invest', 'investment', 'portfolio', 'debt', 'loan', 'credit'])) {
            return "Key financial framework:\n"
                . "1) Keep monthly cashflow positive.\n"
                . "2) Build emergency fund before risky moves.\n"
                . "3) Prioritize high-interest debt payoff.\n"
                . "4) Automate transfers to goals.\n"
                . "5) Invest long-term with diversification.";
        }

        return "I may have misunderstood your request.\n"
            . "Please rephrase with one clear goal, for example:\n"
            . "- \"Explain X in simple terms\"\n"
            . "- \"Give me a step-by-step plan for Y\"\n"
            . "- \"Quiz me on math/coding/finance\"\n"
            . "- \"Use my savings data to suggest priorities\"";
    }

    private function buildQuizReply(string $topic = 'finance'): string
    {
        $t = mb_strtolower(trim($topic));
        if (!in_array($t, ['finance', 'math', 'coding', 'language', 'general'], true)) {
            $t = 'general';
        }

        if ($t === 'math') {
            return $this->pick([
                "Math quiz (3 questions):\n"
                    . "1) Solve: 2x + 7 = 19.\n"
                    . "2) What is the derivative of x^2 + 3x?\n"
                    . "3) A value grows by 10% then 10%. Is total growth 20%? Explain.",
                "Math quiz (3 questions):\n"
                    . "1) Solve: 3x - 5 = 16.\n"
                    . "2) Compute the area of a triangle with base 10 and height 6.\n"
                    . "3) If P(A)=0.4 and P(B)=0.5 and A,B independent, what is P(A and B)?",
            ]);
        }

        if ($t === 'coding') {
            return "Coding quiz (3 questions):\n"
                . "1) What is the difference between an array and an object in JavaScript?\n"
                . "2) Why can an API return HTTP 400?\n"
                . "3) In Symfony, what is the role of a service class?";
        }

        if ($t === 'language') {
            return "Language quiz (3 questions):\n"
                . "1) Translate to French: \"I will review my plan tomorrow.\"\n"
                . "2) Correct this sentence: \"He don't have enough time.\"\n"
                . "3) Give one formal and one informal way to greet in English.";
        }

        if ($t === 'general') {
            return "General knowledge quiz (3 questions):\n"
                . "1) What is photosynthesis?\n"
                . "2) Why do we use version control like Git?\n"
                . "3) What is the difference between correlation and causation?";
        }

        return $this->pick([
            "Finance quiz:\nQ1) If your income is 3000 and expenses are 2600, what is your saving capacity?\nQ2) Why is an emergency fund important before investing?\nQ3) What is better first: high-interest debt payoff or low-interest debt payoff?",
            "Quick finance quiz (3 questions):\n1) What does inflation do to purchasing power?\n2) What is DCA in investing?\n3) If your goal is in 8 months, should you use high-volatility assets?",
            "Money challenge quiz:\n1) If you cut 12% from a 900 TND category, how much do you free monthly?\n2) What is a healthy debt-to-income mindset?\n3) Why should goals have deadlines and priority levels?",
        ]);
    }

    private function buildAnotherQuizReply(string $topic, string $lastAssistantMsg): string
    {
        $t = mb_strtolower(trim($topic));
        $last = mb_strtolower(trim($lastAssistantMsg));

        if ($t === 'math') {
            $v1 = "Math quiz (3 questions):\n"
                . "1) Solve: 2x + 7 = 19.\n"
                . "2) What is the derivative of x^2 + 3x?\n"
                . "3) A value grows by 10% then 10%. Is total growth 20%? Explain.";
            $v2 = "Math quiz (3 questions):\n"
                . "1) Solve: 3x - 5 = 16.\n"
                . "2) Compute the area of a triangle with base 10 and height 6.\n"
                . "3) If P(A)=0.4 and P(B)=0.5 and A,B independent, what is P(A and B)?";
            return str_contains($last, '2x + 7 = 19') ? $v2 : $v1;
        }

        if ($t === 'coding') {
            $v1 = "Coding quiz (3 questions):\n"
                . "1) What is the difference between an array and an object in JavaScript?\n"
                . "2) Why can an API return HTTP 400?\n"
                . "3) In Symfony, what is the role of a service class?";
            $v2 = "Coding quiz (3 questions):\n"
                . "1) What is the difference between GET and POST?\n"
                . "2) Why do we use dependency injection?\n"
                . "3) What is a database migration?";
            return str_contains($last, 'array and an object') ? $v2 : $v1;
        }

        if ($t === 'language') {
            $v1 = "Language quiz (3 questions):\n"
                . "1) Translate to French: \"I will review my plan tomorrow.\"\n"
                . "2) Correct this sentence: \"He don't have enough time.\"\n"
                . "3) Give one formal and one informal way to greet in English.";
            $v2 = "Language quiz (3 questions):\n"
                . "1) Translate to English: \"Je vais commencer aujourd'hui.\"\n"
                . "2) Which is correct: \"fewer\" or \"less\" for countable nouns?\n"
                . "3) Rewrite this politely: \"Send me the file now.\"";
            return str_contains($last, 'review my plan tomorrow') ? $v2 : $v1;
        }

        return $this->buildQuizReply($t === '' ? 'general' : $t);
    }

    private function buildAppInsightReply(array $dbContext): string
    {
        $remaining = (float) ($dbContext['remainingTotal'] ?? 0.0);
        $risk = (int) ($dbContext['goalsAtRisk'] ?? 0);
        $topGoal = (string) (($dbContext['topGoal']['name'] ?? 'your top goal'));
        $balance = (float) ($dbContext['balanceNow'] ?? 0.0);

        $variants = [
            "About this application:\n- It tracks your real financial goals and calculates risk by deadline.\n- It helps you test scenarios before committing money.\n- Right now your profile shows remaining " . number_format($remaining, 2, '.', '') . " TND and {$risk} risky goals.",
            "What this app is useful for:\n- Turning goals into concrete monthly targets.\n- Detecting which goals are at risk early.\n- Guiding weekly actions. Current focus candidate: {$topGoal} (balance now " . number_format($balance, 2, '.', '') . " TND).",
            "This application is your decision layer:\n- Data view: goals, balance, risk.\n- Strategy view: scenarios and prioritization.\n- Execution view: clear next actions to improve completion speed.",
        ];

        return $this->pick($variants);
    }

    private function buildFallbackReply(array $dbContext, string $question, array $history = []): string
    {
        $q = mb_strtolower(trim($question));
        $risk = (int) ($dbContext['goalsAtRisk'] ?? 0);
        $remaining = (float) ($dbContext['remainingTotal'] ?? 0.0);
        $balance = (float) ($dbContext['balanceNow'] ?? 0.0);
        $net = (float) ($dbContext['net30d'] ?? 0.0);
        $topGoal = (string) (($dbContext['topGoal']['name'] ?? 'top goal'));
        $topGoalRemaining = (float) (($dbContext['topGoal']['remaining'] ?? 0.0));
        $goals = is_array($dbContext['goals'] ?? null) ? $dbContext['goals'] : [];
        $expenses = is_array($dbContext['expenseByCategory30d'] ?? null) ? $dbContext['expenseByCategory30d'] : [];
        $revenues = is_array($dbContext['revenueByType30d'] ?? null) ? $dbContext['revenueByType30d'] : [];
        $goalsCount = count($goals);

        $lastUserMsg = '';
        $lastAssistantMsg = '';
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $h = $history[$i] ?? null;
            if (!is_array($h)) {
                continue;
            }
            if (($h['role'] ?? '') === 'user') {
                $lastUserMsg = mb_strtolower(trim((string) ($h['content'] ?? '')));
                if ($lastUserMsg !== '') {
                    break;
                }
            }
        }
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $h = $history[$i] ?? null;
            if (!is_array($h)) {
                continue;
            }
            if (($h['role'] ?? '') === 'assistant') {
                $lastAssistantMsg = mb_strtolower(trim((string) ($h['content'] ?? '')));
                if ($lastAssistantMsg !== '') {
                    break;
                }
            }
        }

        if ($this->containsAny($q, ['hi', 'hello', 'hey', 'how are you', 'how are u', 'salut', 'bonsoir'])) {
            return "I am ready to help.\n"
                . "Current snapshot: balance " . number_format($balance, 2, '.', '') . " TND, goals {$goalsCount}, risk goals {$risk}.\n"
                . "Ask me directly: \"show my interface data\" or \"what should I cut first\".";
        }

        $topCuts = [];
        foreach (array_slice($expenses, 0, 2) as $e) {
            $cat = (string) ($e['category'] ?? 'Other');
            $total = max(0.0, (float) ($e['total'] ?? 0.0));
            if ($total > 0) {
                $topCuts[] = sprintf('%s: save ~%.0f TND/mo', $cat, $total * 0.15);
            }
        }

        $topIncome = [];
        foreach (array_slice($revenues, 0, 2) as $r) {
            $type = (string) ($r['type'] ?? 'income');
            $total = max(0.0, (float) ($r['total'] ?? 0.0));
            if ($total > 0) {
                $topIncome[] = sprintf('%s: add ~%.0f TND/mo', $type, $total * 0.10);
            }
        }

        if ($this->containsAny($q, [
            'what are the goals i have', 'what goals do i have', 'show my goals', 'list my goals',
            'quels sont mes objectifs', 'mes objectifs', 'liste de mes goals', 'show goals'
        ])) {
            if (count($goals) === 0) {
                return "You currently have no goals in your account.\nYou can add your first goal in the Goals tab.";
            }

            $lines = ["Here are your current goals (from your DB):"];
            foreach (array_slice($goals, 0, 8) as $g) {
                $name = (string) ($g['name'] ?? 'Goal');
                $remainingG = (float) ($g['remaining'] ?? 0.0);
                $deadline = (string) (($g['deadline'] ?? '') ?: 'no deadline');
                $priority = (int) ($g['priority'] ?? 3);
                $lines[] = sprintf(
                    "- %s | remaining %.2f TND | deadline %s | P%d",
                    $name,
                    $remainingG,
                    $deadline,
                    $priority
                );
            }
            if (count($goals) > 8) {
                $lines[] = sprintf("- ...and %d more goal(s).", count($goals) - 8);
            }

            return implode("\n", $lines);
        }

        if ($this->containsAny($q, ['quiz', 'quizz', 'question me', 'test me', 'ask me questions'])) {
            $topic = $this->detectTopic($q);
            if ($topic === 'general' && $this->containsAny($q, ['finance', 'money', 'budget', 'saving'])) {
                $topic = 'finance';
            }
            return $this->buildQuizReply($topic);
        }

        if ($this->containsAny($q, ['another one', 'another', 'anither', 'give another', 'change your answer', 'different answer'])) {
            $topic = $this->detectTopic($lastUserMsg);
            if ($topic === 'general' && str_contains($lastAssistantMsg, 'quiz')) {
                if ($this->containsAny($lastAssistantMsg, ['math quiz'])) {
                    $topic = 'math';
                } elseif ($this->containsAny($lastAssistantMsg, ['coding quiz'])) {
                    $topic = 'coding';
                } elseif ($this->containsAny($lastAssistantMsg, ['language quiz'])) {
                    $topic = 'language';
                } else {
                    $topic = 'finance';
                }
            }
            return $this->buildAnotherQuizReply($topic, $lastAssistantMsg);
        }

        if ($this->containsAny($q, ['about this application', 'about this app', 'tell me about this application', 'what do you want to tell me about this application'])) {
            return $this->buildAppInsightReply($dbContext);
        }

        if ($this->containsAny($q, ['conservative vs aggressive', 'conservative', 'aggressive'])) {
            return $this->buildConservativeVsAggressive($dbContext);
        }

        if ($this->containsAny($q, ['risk in goals', 'avoid risk in goals', 'how to avoid risk', 'reduce risk goals', 'goal risk'])) {
            $first = $goals[0]['name'] ?? 'highest-priority goal';
            return "To reduce goal risk, use this order:\n"
                . "1) Fund urgent goal first: {$first}\n"
                . "2) Increase monthly transfer until risk goals drop below 1\n"
                . "3) Keep emergency buffer to avoid missed contributions\n"
                . "Current risk signal: {$risk} goal(s) at risk.";
        }

        if ($this->containsAny($q, ['cut', 'reduce', 'expense'])) {
            $base = "Based on your data, cut highest-impact categories first.";
            $cuts = count($topCuts) ? "Priority cuts: " . implode(' | ', $topCuts) . "." : "Start with a 10-15% cut on non-essential categories.";
            $riskLine = $risk > 0 ? "You currently have {$risk} goals at risk, so redirect every saved TND to urgent goals." : "You can use cuts to accelerate completion time.";
            return "{$base}\n{$cuts}\n{$riskLine}";
        }

        if ($this->containsAny($q, ['business', 'buissness', 'startup', 'funding', 'raise money'])) {
            $monthly = max(150.0, round($remaining / 18.0, 0));
            return "Yes, there are realistic ways to fund a business:\n"
                . "1) Bootstrap: save a fixed amount monthly (target ~{$monthly} TND).\n"
                . "2) Pre-sell: validate demand before spending heavily.\n"
                . "3) Partner/angel/micro-loan: use only after a simple revenue plan.\n"
                . "4) Keep your personal emergency buffer protected.\n"
                . "If you want, I can build a 90-day funding plan from your current cashflow.";
        }

        if ($this->containsAny($q, ['income', 'earn', 'gain', 'side hustle', 'extra money', 'get money', 'make money'])) {
            $inc = count($topIncome) ? "Best income levers now: " . implode(' | ', $topIncome) . "." : "Target +200 to +400 TND/mo via one side stream.";
            return "To accelerate goals, combine stable income boost with automatic weekly transfer.\n{$inc}\nKeep savings transfer fixed every week.";
        }

        if ($this->containsAny($q, ['data aware', 'are data aware', 'are you data aware', 'db aware', 'database aware'])) {
            return "Yes. I am DB-aware for your savings/goals context.\n"
                . "Current snapshot: balance " . number_format($balance, 2, '.', '') . " TND, goals {$goalsCount}, risk goals {$risk}.\n"
                . "Ask: \"which goal should be funded first based on deadline and remaining amount\".";
        }

        if ($this->containsAny($q, ['habits', 'habit', 'discipline', 'consistency', 'progress'])) {
            return "Habits probably hurting your progress:\n"
                . "- No fixed weekly transfer to goals.\n"
                . "- Spending decisions without category caps.\n"
                . "- No 14-day review loop for risky goals.\n"
                . "- Reacting emotionally instead of following a written plan.\n"
                . "Current context: {$risk} goal(s) at risk, remaining " . number_format($remaining, 2, '.', '') . " TND.";
        }

        if ($this->containsAny($q, ['suggestions for goals', 'other suggestions', 'goal suggestions', 'suggest goals'])) {
            $targetMonthly = max(200.0, round($remaining / 12.0, 0));
            return "Goal suggestions:\n"
                . "1) Keep one urgent goal as primary until risk drops.\n"
                . "2) Set auto-transfer target around {$targetMonthly} TND/month.\n"
                . "3) Pause low-priority goals when cashflow is tight.\n"
                . "4) Re-rank goals every 2 weeks using deadline + remaining amount.";
        }

        if ($this->containsAny($q, ['general answer', 'give a general answer'])) {
            return "General answer framework:\n"
                . "1) Clarify objective and deadline.\n"
                . "2) Quantify current resources.\n"
                . "3) Choose one execution plan with weekly checkpoints.\n"
                . "Tell me your exact topic and I will tailor this framework.";
        }

        if (in_array($q, ['ok', 'okay', 'k', 'fine'], true)) {
            return "Understood. Choose one next step:\n"
                . "1) \"build me a 30-day money plan\"\n"
                . "2) \"give business funding options\"\n"
                . "3) \"show goal priorities from my DB\"\n"
                . "4) \"start a math quiz\"";
        }

        if ($this->containsAny($q, ['what should i cut first', 'what should i cut'])) {
            $cuts = count($topCuts) ? implode(' | ', $topCuts) : 'non-essential spending first';
            return "Cut first: {$cuts}.\nThen route 100% of saved cash to {$topGoal} until risk drops.";
        }

        if ($this->containsAny($q, ['nothing', 'do nothing', 'lazy'])) {
            $inflation = round($remaining * 0.11, 2);
            $opp = round($inflation + max(0.0, $topGoalRemaining * 0.05), 2);
            return "If you do nothing, progress stays near zero while deadlines approach.\nEstimated inflation drag on remaining goals: ~{$inflation} TND.\nOpportunity cost signal: ~{$opp} TND+ over time.";
        }

        if ($this->containsAny($q, ['plan', 'strategy'])) {
            $targetMonthly = max(200.0, round($remaining / 12.0, 0));
            return "Strategic plan:\n1) Protect urgent goal: {$topGoal}.\n2) Monthly target: ~{$targetMonthly} TND.\n3) Use cuts + income boosts to close the gap.\nCurrent balance: " . number_format($balance, 2, '.', '') . " TND, net30d: " . number_format($net, 2, '.', '') . " TND.";
        }

        if ($this->containsAny($q, ['interface data', 'data here', 'data in interface', 'savings/goals', 'about the data here', 'info about the data'])) {
            $lines = [
                "Interface data snapshot:",
                "- Balance: " . number_format($balance, 2, '.', '') . " TND",
                "- Goals count: {$goalsCount}",
                "- Remaining total: " . number_format($remaining, 2, '.', '') . " TND",
                "- Risk goals: {$risk}",
            ];
            foreach (array_slice($goals, 0, 3) as $g) {
                $lines[] = sprintf(
                    "- %s: remaining %.2f TND, deadline %s",
                    (string) ($g['name'] ?? 'Goal'),
                    (float) ($g['remaining'] ?? 0.0),
                    (string) (($g['deadline'] ?? '') ?: 'n/a')
                );
            }
            return implode("\n", $lines);
        }

        if ($this->isFollowUpPrompt($q) && $lastUserMsg !== '') {
            if ($this->containsAny($lastUserMsg, ['risk', 'goal'])) {
                return "More on risk reduction:\n"
                    . "- Add a fixed weekly transfer (monthly/4) for urgent goals.\n"
                    . "- Delay low-priority goals until urgent deadlines stabilize.\n"
                    . "- Review risk goals every 14 days.";
            }
            if ($this->containsAny($lastUserMsg, ['data', 'interface', 'goals'])) {
                return "More on your interface data:\n"
                    . "- Use remaining amount + deadline to rank urgency.\n"
                    . "- Focus on P1/P2 goals first for the next 30 days.\n"
                    . "- Re-run simulation after each contribution batch.";
            }
            return "More detail:\n- Convert monthly target into weekly transfers.\n- Cut one expense category and add one income action this week.\n- Recheck progress after 14 days.";
        }

        return $this->buildGeneralFallbackReply($q);
    }

    public function buildSuggestions(array $dbContext): array
    {
        return [
            'What goals do I have?',
            '/help',
            'Contribute 200 TND to car',
            'Create a goal called laptop target 3000 deadline 2026-09-01 priority P2',
            'Rename goal pcc to course',
            'Change skincare target to 1500',
            'Extend car deadline by 2 months',
            'Delete goal skincare',
            'Show my last 5 savings transactions',
            'Undo last contribution',
        ];
    }

    /**
     * @param array<string,mixed> $accPack
     * @param array<int,array<string,mixed>> $history
     * @param array<string,mixed> $state
     * @return array{ok:bool,reply:string,source:string,model:?string,error:?string,state:array<string,mixed>}
     */
    public function handleSavingsGoalsCommand(
        Connection $conn,
        int $userId,
        array $accPack,
        string $goalAccCol,
        string $message,
        array $history,
        array $state
    ): array {
        $raw = trim($message);
        $aiRewrite = $this->rewriteCommandWithAi($raw, $history);
        $q = $this->normalizeText((string) ($aiRewrite['canonical'] ?? $raw));
        $source = ($aiRewrite['used'] ?? false) ? 'openai-local-action' : 'local-action';
        if ($q === '') {
            return ['ok' => false, 'reply' => 'Please enter a message.', 'source' => $source, 'model' => ($aiRewrite['model'] ?? null), 'error' => 'EMPTY', 'state' => $state];
        }

        $accId = (int) ($accPack['accId'] ?? 0);
        if ($accId <= 0) {
            return ['ok' => false, 'reply' => 'No savings account found for your profile.', 'source' => $source, 'model' => ($aiRewrite['model'] ?? null), 'error' => 'NO_ACCOUNT', 'state' => $state];
        }

        if ($this->containsAny($q, ['/help', 'help', 'what can you do', 'commands', 'what can the bot do'])) {
            return ['ok' => true, 'reply' => $this->capabilitiesGuide(), 'source' => $source, 'model' => ($aiRewrite['model'] ?? null), 'error' => null, 'state' => $state];
        }

        $pending = is_array($state['pending'] ?? null) ? $state['pending'] : null;
        if ($pending !== null) {
            return $this->handlePending($conn, $userId, $accPack, $goalAccCol, $q, $pending, $state);
        }

        $goals = $this->loadGoals($conn, $accId, $goalAccCol);
        $match = $this->matchToCatalog($q, $goals);
        if (($match['score'] ?? 0.0) < 0.35) {
            return [
                'ok' => false,
                'source' => $source,
                'model' => ($aiRewrite['model'] ?? null),
                'error' => 'UNKNOWN',
                'state' => $state,
                'reply' => $this->replyBlocks(
                    'I could not map this to a supported command.',
                    'Please use one supported sentence pattern.',
                    'No data changed.',
                    'Type /help to see examples.'
                ),
            ];
        }

        $template = (string) ($match['id'] ?? '');
        $entities = $this->extractEntities($q, $goals);
        $required = $this->requiredEntitiesForTemplate($template);
        foreach ($required as $field) {
            $missing = !isset($entities[$field]) || $entities[$field] === '';
            if ($missing) {
                $state['pending'] = [
                    'type' => 'await_entity',
                    'template' => $template,
                    'entities' => $entities,
                    'missing' => $field,
                ];
                return [
                    'ok' => false,
                    'source' => $source,
                    'model' => ($aiRewrite['model'] ?? null),
                    'error' => 'MISSING_ENTITY',
                    'state' => $state,
                    'reply' => $this->askForMissingEntity($field),
                ];
            }
        }

        $result = $this->executeTemplate($template, $conn, $userId, $accPack, $goalAccCol, $q, $state, $entities);
        if (($result['ok'] ?? false) === true && !empty($match['canonical'])) {
            $result['reply'] = "Interpreted as: " . (string) $match['canonical'] . "\n" . (string) ($result['reply'] ?? '');
        }
        return $result;
    }

    private function capabilitiesGuide(): string
    {
        return "Result: I can execute Savings & Goals actions from natural language.\n"
            . "Updated data: Supported commands:\n"
            . "- Questions: what goals do i have | how much remaining for skincare | what is the target for car | what is the deadline for car\n"
            . "- Goal CRUD: create a goal called clothes target 3000 tnd deadline 2026-09-01 priority p2 | rename goal pcc to course | set car target to 5000 | extend car deadline by 2 months | delete goal skincare\n"
            . "- Contributions: contribute 200 tnd to car | 1 dinar to skincare | undo last contribution\n"
            . "French examples the bot understands:\n"
            . "- Questions: quels sont mes objectifs | combien il reste pour skincare | quel est le target de car | c est quoi la deadline de car\n"
            . "- Goal CRUD: cree un objectif nomme clothes target 3000 tnd deadline 2026-09-01 priorite p2 | renomme objectif pcc en course | fixe target car a 5000 | prolonge deadline car de 2 mois | supprime objectif skincare\n"
            . "- Contributions: ajoute 200 tnd a car | 1 dinar a skincare | annuler la derniere contribution\n"
            . "Impact: All answers/actions use your real DB data, scoped to your account.\n"
            . "Next suggestion: Try one command exactly: what goals do i have";
    }

    /**
     * @param array<string,mixed> $accPack
     * @return array<string,mixed>
     */
    public function buildSavingsGoalsSnapshot(Connection $conn, int $userId, array $accPack, string $goalAccCol): array
    {
        $accId = (int) ($accPack['accId'] ?? 0);
        $balanceNow = (float) ($accPack['currentAccount'][$accPack['accBalanceCol']] ?? 0.0);
        $goals = $accId > 0 ? $conn->fetchAllAssociative(
            "SELECT nom, montant_cible, montant_actuel, date_limite FROM financial_goal WHERE `$goalAccCol` = :acc",
            ['acc' => $accId]
        ) : [];
        $goalsAtRisk = 0;
        $today = new \DateTimeImmutable('today');
        foreach ($goals as $g) {
            $remaining = max(0.0, (float) ($g['montant_cible'] ?? 0.0) - (float) ($g['montant_actuel'] ?? 0.0));
            if ($remaining <= 0.0 || empty($g['date_limite'])) {
                continue;
            }
            $d = new \DateTimeImmutable((string) $g['date_limite']);
            $days = (int) $today->diff($d)->format('%r%a');
            if ($days <= 30) {
                $goalsAtRisk++;
            }
        }
        return [
            'userId' => $userId,
            'balanceNow' => round($balanceNow, 2),
            'goalsAtRisk' => $goalsAtRisk,
        ];
    }

    /**
     * @param array<string,mixed> $pending
     * @param array<string,mixed> $state
     * @param array<string,mixed> $accPack
     * @return array{ok:bool,reply:string,source:string,model:?string,error:?string,state:array<string,mixed>}
     */
    private function handlePending(
        Connection $conn,
        int $userId,
        array $accPack,
        string $goalAccCol,
        string $message,
        array $pending,
        array $state
    ): array {
        $yes = preg_match('/^\s*(yes|y|confirm|oui)\s*$/i', $message) === 1;
        $no = preg_match('/^\s*(no|n|cancel|non)\s*$/i', $message) === 1;
        $type = (string) ($pending['type'] ?? '');

        if ($type === 'confirm_delete_goal') {
            if ($no) {
                unset($state['pending']);
                return ['ok' => true, 'reply' => 'Deletion cancelled.', 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
            }
            if (!$yes) {
                return ['ok' => false, 'reply' => 'Please answer yes or no.', 'source' => 'local-action', 'model' => null, 'error' => 'CONFIRM_NEEDED', 'state' => $state];
            }
            $gid = (int) ($pending['goal_id'] ?? 0);
            $accId = (int) ($accPack['accId'] ?? 0);
            if ($gid > 0 && $accId > 0) {
                $conn->executeStatement("DELETE FROM financial_goal WHERE id = :gid AND `$goalAccCol` = :acc", ['gid' => $gid, 'acc' => $accId]);
            }
            unset($state['pending']);
            return ['ok' => true, 'reply' => $this->replyBlocks('Goal deleted.', 'Goal removed from your list.', 'Progress recalculated on remaining goals.', 'Say "what goals do I have?" to verify.'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
        }

        if ($type === 'confirm_delete_account') {
            if ($no) {
                unset($state['pending']);
                return ['ok' => true, 'reply' => 'Deletion cancelled.', 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
            }
            if (!$yes) {
                return ['ok' => false, 'reply' => 'Please answer yes or no.', 'source' => 'local-action', 'model' => null, 'error' => 'CONFIRM_NEEDED', 'state' => $state];
            }
            $aid = (int) ($pending['account_id'] ?? 0);
            $userCol = (string) ($accPack['accUserCol'] ?? 'user_id');
            if ($aid > 0) {
                $conn->executeStatement("DELETE FROM saving_account WHERE id = :id AND `$userCol` = :uid", ['id' => $aid, 'uid' => $userId]);
            }
            unset($state['pending']);
            return ['ok' => true, 'reply' => $this->replyBlocks('Savings account deleted.', 'Account removed successfully.', 'Total balance changed accordingly.', 'Say "what savings accounts do I have?" to verify.'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
        }

        if ($type === 'confirm_undo_contribution') {
            if ($no) {
                unset($state['pending']);
                return ['ok' => true, 'reply' => 'Undo cancelled.', 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
            }
            if (!$yes) {
                return ['ok' => false, 'reply' => 'Please answer yes or no.', 'source' => 'local-action', 'model' => null, 'error' => 'CONFIRM_NEEDED', 'state' => $state];
            }
            unset($state['pending']);
            return $this->undoLastContribution($conn, $userId, $accPack, $goalAccCol, $state);
        }

        if ($type === 'await_entity') {
            $template = (string) ($pending['template'] ?? '');
            $entities = is_array($pending['entities'] ?? null) ? $pending['entities'] : [];
            $missing = (string) ($pending['missing'] ?? '');
            $accId = (int) ($accPack['accId'] ?? 0);
            if ($missing === 'goal') {
                $goal = $this->findGoalByName($this->loadGoals($conn, $accId, $goalAccCol), $message);
                if ($goal === null) return ['ok' => false, 'reply' => 'Which goal?', 'source' => 'local-action', 'model' => null, 'error' => 'GOAL_MISSING', 'state' => $state];
                $entities['goal'] = (string) ($goal['nom'] ?? '');
            } elseif ($missing === 'amount') {
                $amount = $this->extractAmount($message);
                if ($amount === null || $amount <= 0) return ['ok' => false, 'reply' => 'How much TND?', 'source' => 'local-action', 'model' => null, 'error' => 'AMOUNT_MISSING', 'state' => $state];
                $entities['amount'] = $amount;
            } elseif ($missing === 'date') {
                $date = $this->extractDate($message);
                if ($date === null) return ['ok' => false, 'reply' => 'What deadline date? (YYYY-MM-DD)', 'source' => 'local-action', 'model' => null, 'error' => 'DATE_MISSING', 'state' => $state];
                $entities['date'] = $date;
            } elseif ($missing === 'months') {
                $months = $this->extractMonths($message);
                if ($months === null || $months <= 0) return ['ok' => false, 'reply' => 'How many months?', 'source' => 'local-action', 'model' => null, 'error' => 'MONTHS_MISSING', 'state' => $state];
                $entities['months'] = $months;
            } elseif ($missing === 'priority') {
                $priority = $this->extractPriority($message);
                if ($priority === null) return ['ok' => false, 'reply' => 'What priority? (P1..P4)', 'source' => 'local-action', 'model' => null, 'error' => 'PRIORITY_MISSING', 'state' => $state];
                $entities['priority'] = $priority;
            } elseif ($missing === 'new_name') {
                $value = trim($message);
                if ($value === '') return ['ok' => false, 'reply' => 'What is the new goal name?', 'source' => 'local-action', 'model' => null, 'error' => 'NAME_MISSING', 'state' => $state];
                $entities['new_name'] = $value;
            } elseif ($missing === 'name') {
                $value = trim($message);
                if ($value === '') return ['ok' => false, 'reply' => 'What is the goal name?', 'source' => 'local-action', 'model' => null, 'error' => 'NAME_MISSING', 'state' => $state];
                $entities['name'] = $value;
            }
            foreach ($this->requiredEntitiesForTemplate($template) as $field) {
            $isMissing = !isset($entities[$field]) || $entities[$field] === '';
                if ($isMissing) {
                    $state['pending'] = [
                        'type' => 'await_entity',
                        'template' => $template,
                        'entities' => $entities,
                        'missing' => $field,
                    ];
                    return ['ok' => false, 'reply' => $this->askForMissingEntity($field), 'source' => 'local-action', 'model' => null, 'error' => 'MISSING_ENTITY', 'state' => $state];
                }
            }
            unset($state['pending']);
            return $this->executeTemplate($template, $conn, $userId, $accPack, $goalAccCol, $message, $state, $entities);
        }

        if ($type === 'create_goal') {
            $data = is_array($pending['data'] ?? null) ? $pending['data'] : [];
            $nextField = (string) ($pending['next_field'] ?? 'name');
            if ($nextField === 'name') {
                $data['name'] = trim($message);
                $state['pending'] = ['type' => 'create_goal', 'data' => $data, 'next_field' => 'target'];
                return ['ok' => false, 'reply' => 'What is the target amount in TND?', 'source' => 'local-action', 'model' => null, 'error' => 'NEED_TARGET', 'state' => $state];
            }
            if ($nextField === 'target') {
                $amount = $this->extractAmount($message);
                if ($amount === null || $amount <= 0.0) {
                    return ['ok' => false, 'reply' => 'Please provide a valid positive target amount.', 'source' => 'local-action', 'model' => null, 'error' => 'INVALID_TARGET', 'state' => $state];
                }
                $data['target'] = $amount;
                $state['pending'] = ['type' => 'create_goal', 'data' => $data, 'next_field' => 'deadline'];
                return ['ok' => false, 'reply' => 'What is the deadline? (YYYY-MM-DD) or say "skip".', 'source' => 'local-action', 'model' => null, 'error' => 'NEED_DEADLINE', 'state' => $state];
            }
            if ($nextField === 'deadline') {
                $deadline = null;
                if (preg_match('/\bskip\b/i', $message) !== 1) {
                    $deadline = $this->extractDate($message);
                    if ($deadline === null) {
                        return ['ok' => false, 'reply' => 'Please provide a valid date as YYYY-MM-DD, or say "skip".', 'source' => 'local-action', 'model' => null, 'error' => 'INVALID_DATE', 'state' => $state];
                    }
                }
                $name = (string) ($data['name'] ?? 'Goal');
                $target = (float) ($data['target'] ?? 0.0);
                $priority = 3;
                $accId = (int) ($accPack['accId'] ?? 0);
                $conn->insert('financial_goal', [
                    'nom' => $name,
                    'montant_cible' => $target,
                    'montant_actuel' => 0,
                    'date_limite' => $deadline,
                    'priorite' => $priority,
                    $goalAccCol => $accId,
                ]);
                unset($state['pending']);
                return ['ok' => true, 'reply' => $this->replyBlocks(
                    sprintf('Goal "%s" created.', $name),
                    sprintf('Target %.2f TND | Current 0.00 TND | Deadline %s', $target, $deadline ?? 'none'),
                    'Progress is 0% and ready for first contribution.',
                    sprintf('Try: "contribute 100 TND to %s".', $name)
                ), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
            }
        }

        unset($state['pending']);
        return ['ok' => false, 'reply' => 'Pending request cleared. Please send your command again.', 'source' => 'local-action', 'model' => null, 'error' => 'PENDING_CLEARED', 'state' => $state];
    }

    private function replyBlocks(string $result, string $updated, string $impact, string $next): string
    {
        return "? Result: {$result}\n?? Updated data: {$updated}\n?? Impact: {$impact}\n? Next suggestion: {$next}";
    }

    private function askForMissingEntity(string $field): string
    {
        return match ($field) {
            'goal' => 'Which goal?',
            'amount' => 'How much TND?',
            'date' => 'What deadline date? (YYYY-MM-DD)',
            'months' => 'How many months?',
            'priority' => 'What priority? (P1..P4)',
            'new_name' => 'What is the new goal name?',
            'name' => 'What is the goal name?',
            default => 'Please provide the missing information.',
        };
    }

    private function extractAmount(string $text): ?float
    {
        if (preg_match('/(-?\d+(?:[.,]\d{1,2})?)/', $text, $m) !== 1) {
            return null;
        }
        return (float) str_replace(',', '.', (string) $m[1]);
    }

    private function extractDate(string $text): ?string
    {
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $m) !== 1) {
            return null;
        }
        try {
            return (new \DateTimeImmutable((string) $m[1]))->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadGoals(Connection $conn, int $accId, string $goalAccCol): array
    {
        if ($accId <= 0) {
            return [];
        }
        return $conn->fetchAllAssociative(
            "SELECT id, nom, montant_cible, montant_actuel, date_limite, priorite FROM financial_goal WHERE `$goalAccCol` = :acc ORDER BY priorite ASC, date_limite ASC",
            ['acc' => $accId]
        );
    }

    /**
     * @param array<int,array<string,mixed>> $goals
     */
    private function findGoalByName(array $goals, string $message): ?array
    {
        $q = mb_strtolower(trim($message));
        $best = null;
        $bestScore = -1.0;
        $tokens = array_values(array_filter(explode(' ', $q), static fn(string $t): bool => $t !== ''));
        foreach ($goals as $g) {
            $name = mb_strtolower(trim((string) ($g['nom'] ?? '')));
            if ($name === '') {
                continue;
            }
            $score = 0.0;
            if (str_contains($q, $name)) {
                $score = 2.0 + (mb_strlen($name) / 100.0);
            } else {
                $nameTokens = array_values(array_filter(explode(' ', $name), static fn(string $t): bool => $t !== ''));
                foreach ($nameTokens as $nt) {
                    foreach ($tokens as $t) {
                        if ($t === $nt) {
                            $score += 1.0;
                        } elseif (levenshtein($t, $nt) <= 1) {
                            $score += 0.7;
                        }
                    }
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $g;
            }
        }
        return $bestScore > 0.5 ? $best : null;
    }

    private function answerGoalsList(Connection $conn, int $accId, string $goalAccCol, array $state): array
    {
        $goals = $this->loadGoals($conn, $accId, $goalAccCol);
        if (count($goals) === 0) {
            return ['ok' => true, 'reply' => $this->replyBlocks('No goals found.', '0 goals in your account.', 'No deadline risk.', 'Create one: "create a goal called laptop target 3000".'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
        }
        $lines = [];
        foreach (array_slice($goals, 0, 5) as $g) {
            $remaining = max(0.0, (float) $g['montant_cible'] - (float) $g['montant_actuel']);
            $lines[] = sprintf('%s (remaining %.2f TND, deadline %s, P%d)', (string) $g['nom'], $remaining, (string) ($g['date_limite'] ?? 'none'), (int) ($g['priorite'] ?? 3));
        }
        return ['ok' => true, 'reply' => $this->replyBlocks('Fetched your goals.', implode(' | ', $lines), 'Use priority/deadline to decide next contribution.', 'Ask: "how much remaining for <goal>?"'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    private function answerGoalRemaining(Connection $conn, int $accId, string $goalAccCol, string $q, array $state): array
    {
        $goals = $this->loadGoals($conn, $accId, $goalAccCol);
        $goal = $this->findGoalByName($goals, $q);
        if (!$goal) {
            return ['ok' => false, 'reply' => 'Which goal name do you mean?', 'source' => 'local-action', 'model' => null, 'error' => 'GOAL_MISSING', 'state' => $state];
        }
        $remaining = max(0.0, (float) $goal['montant_cible'] - (float) $goal['montant_actuel']);
        $progress = ((float) $goal['montant_cible'] > 0) ? min(100.0, ((float) $goal['montant_actuel'] / (float) $goal['montant_cible']) * 100.0) : 0.0;
        return ['ok' => true, 'reply' => $this->replyBlocks(
            sprintf('Remaining for "%s" is %.2f TND.', (string) $goal['nom'], $remaining),
            sprintf('Target %.2f | Current %.2f | Deadline %s', (float) $goal['montant_cible'], (float) $goal['montant_actuel'], (string) ($goal['date_limite'] ?? 'none')),
            sprintf('Progress is %.1f%%.', $progress),
            sprintf('You can contribute now: "contribute 100 TND to %s".', (string) $goal['nom'])
        ), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    private function answerGoalTarget(Connection $conn, int $accId, string $goalAccCol, string $q, array $state): array
    {
        $goals = $this->loadGoals($conn, $accId, $goalAccCol);
        $goal = $this->findGoalByName($goals, $q);
        if (!$goal) {
            return ['ok' => false, 'reply' => 'Which goal name do you mean?', 'source' => 'local-action', 'model' => null, 'error' => 'GOAL_MISSING', 'state' => $state];
        }
        return ['ok' => true, 'reply' => $this->replyBlocks(
            sprintf('Target for "%s" is %.2f TND.', (string) $goal['nom'], (float) $goal['montant_cible']),
            sprintf('Current %.2f | Remaining %.2f', (float) $goal['montant_actuel'], max(0.0, (float) $goal['montant_cible'] - (float) $goal['montant_actuel'])),
            'Target data loaded from your DB.',
            'Ask "change <goal> target to <amount>" if you want to update it.'
        ), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    private function answerGoalDeadline(Connection $conn, int $accId, string $goalAccCol, string $q, array $state): array
    {
        $goals = $this->loadGoals($conn, $accId, $goalAccCol);
        $goal = $this->findGoalByName($goals, $q);
        if (!$goal) {
            return ['ok' => false, 'reply' => 'Which goal?', 'source' => 'local-action', 'model' => null, 'error' => 'GOAL_MISSING', 'state' => $state];
        }
        $deadline = (string) ($goal['date_limite'] ?? 'none');
        return ['ok' => true, 'reply' => $this->replyBlocks(
            sprintf('Deadline for "%s" is %s.', (string) $goal['nom'], $deadline),
            sprintf('Target %.2f | Current %.2f', (float) $goal['montant_cible'], (float) $goal['montant_actuel']),
            'Deadline data loaded from your DB.',
            sprintf('You can say: extend %s deadline by 2 months.', (string) $goal['nom'])
        ), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    /** @phpstan-ignore-next-line */
    private function answerGoalsAtRisk(Connection $conn, int $accId, string $goalAccCol, array $state): array
    {
        $goals = $this->loadGoals($conn, $accId, $goalAccCol);
        $today = new \DateTimeImmutable('today');
        $risk = [];
        foreach ($goals as $g) {
            $remaining = max(0.0, (float) $g['montant_cible'] - (float) $g['montant_actuel']);
            if ($remaining <= 0.0 || empty($g['date_limite'])) {
                continue;
            }
            $d = new \DateTimeImmutable((string) $g['date_limite']);
            $days = (int) $today->diff($d)->format('%r%a');
            if ($days <= 30) {
                $risk[] = sprintf('%s (%d days, %.2f TND remaining)', (string) $g['nom'], $days, $remaining);
            }
        }
        $txt = count($risk) ? implode(' | ', array_slice($risk, 0, 5)) : 'No goals in near-deadline risk.';
        return ['ok' => true, 'reply' => $this->replyBlocks('Checked deadline risk.', $txt, sprintf('%d goal(s) flagged in the next 30 days.', count($risk)), 'Prioritize the first risk goal in your next contribution.'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    /** @phpstan-ignore-next-line */
    private function answerAccounts(Connection $conn, int $userId, array $accPack, array $state): array
    {
        $userCol = (string) ($accPack['accUserCol'] ?? 'user_id');
        $balCol = (string) ($accPack['accBalanceCol'] ?? 'sold');
        $rows = $conn->fetchAllAssociative("SELECT id, `$balCol` AS balance FROM saving_account WHERE `$userCol` = :uid ORDER BY id DESC", ['uid' => $userId]);
        if (!$rows) {
            return ['ok' => true, 'reply' => $this->replyBlocks('No savings accounts found.', '0 account entries.', 'No available balance to allocate.', 'Create one: "create savings account called Main with 500 TND".'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
        }
        $items = array_map(static fn(array $r): string => sprintf('Account #%d balance %.2f TND', (int) $r['id'], (float) $r['balance']), array_slice($rows, 0, 5));
        return ['ok' => true, 'reply' => $this->replyBlocks('Fetched your savings accounts.', implode(' | ', $items), 'Balances loaded from DB.', 'Use "total savings balance" for aggregate view.'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    /** @phpstan-ignore-next-line */
    private function answerTotalBalance(Connection $conn, int $userId, array $accPack, array $state): array
    {
        $userCol = (string) ($accPack['accUserCol'] ?? 'user_id');
        $balCol = (string) ($accPack['accBalanceCol'] ?? 'sold');
        $total = (float) $conn->fetchOne("SELECT COALESCE(SUM(`$balCol`),0) FROM saving_account WHERE `$userCol` = :uid", ['uid' => $userId]);
        return ['ok' => true, 'reply' => $this->replyBlocks(sprintf('Total savings balance is %.2f TND.', $total), sprintf('Aggregate across your savings accounts: %.2f TND', $total), 'Use this amount for contribution planning.', 'Ask "what goals do I have?" to allocate this balance.'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    /** @phpstan-ignore-next-line */
    private function answerRecentTransactions(Connection $conn, int $userId, string $q, array $state): array
    {
        $limit = 5;
        if (preg_match('/last\s+(\d{1,2})/i', $q, $m) === 1) {
            $limit = max(1, min(20, (int) $m[1]));
        }
        $rows = $conn->fetchAllAssociative(
            "SELECT id, type, montant, `date`, description FROM `transaction` WHERE user_id = :uid AND module_source = :src ORDER BY `date` DESC, id DESC LIMIT {$limit}",
            ['uid' => $userId, 'src' => 'SAVINGS']
        );
        $items = array_map(static fn(array $r): string => sprintf('#%d %s %.2f on %s (%s)', (int) $r['id'], (string) $r['type'], (float) $r['montant'], (string) $r['date'], (string) $r['description']), $rows);
        return ['ok' => true, 'reply' => $this->replyBlocks("Fetched your last {$limit} savings transactions.", $items ? implode(' | ', $items) : 'No transactions found.', 'History pulled from your DB.', 'Use contributions or goal actions as your next step.'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    /** @phpstan-ignore-next-line */
    private function answerSavedThisMonth(Connection $conn, int $userId, array $state): array
    {
        $sum = (float) $conn->fetchOne(
            "SELECT COALESCE(SUM(montant),0) FROM `transaction` WHERE user_id = :uid AND module_source = :src AND type = 'EPARGNE' AND YEAR(`date`) = YEAR(CURDATE()) AND MONTH(`date`) = MONTH(CURDATE())",
            ['uid' => $userId, 'src' => 'SAVINGS']
        );
        return ['ok' => true, 'reply' => $this->replyBlocks(sprintf('You saved %.2f TND this month.', $sum), sprintf('Month-to-date savings deposits: %.2f TND', $sum), 'Monthly savings trend updated.', 'If needed, increase auto deposit for next month.'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    /** @phpstan-ignore-next-line */
    private function answerContributedToGoal(Connection $conn, int $accId, string $goalAccCol, int $userId, string $q, array $state): array
    {
        $goal = $this->findGoalByName($this->loadGoals($conn, $accId, $goalAccCol), $q);
        if (!$goal) {
            return ['ok' => false, 'reply' => 'Which goal name should I use?', 'source' => 'local-action', 'model' => null, 'error' => 'GOAL_MISSING', 'state' => $state];
        }
        $gid = (int) $goal['id'];
        $sum = (float) $conn->fetchOne(
            "SELECT COALESCE(SUM(montant),0) FROM `transaction` WHERE user_id = :uid AND module_source = :src AND type = 'GOAL_CONTRIB' AND description LIKE :pattern",
            ['uid' => $userId, 'src' => 'SAVINGS', 'pattern' => '%#' . $gid . '%']
        );
        return ['ok' => true, 'reply' => $this->replyBlocks(sprintf('Total contributions to "%s": %.2f TND.', (string) $goal['nom'], $sum), sprintf('Goal current %.2f / target %.2f', (float) $goal['montant_actuel'], (float) $goal['montant_cible']), 'Contribution total computed from savings history.', sprintf('Say "show contributions for goal %s only".', (string) $goal['nom'])), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    /** @phpstan-ignore-next-line */
    private function answerAverageMonthlySaving(Connection $conn, int $userId, array $state): array
    {
        $rows = $conn->fetchAllAssociative(
            "SELECT DATE_FORMAT(`date`, '%Y-%m') AS ym, COALESCE(SUM(montant),0) AS s
             FROM `transaction`
             WHERE user_id = :uid AND module_source = :src AND type = 'EPARGNE' AND `date` >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
             GROUP BY DATE_FORMAT(`date`, '%Y-%m')
             ORDER BY ym DESC",
            ['uid' => $userId, 'src' => 'SAVINGS']
        );
        $vals = array_map(static fn(array $r): float => (float) ($r['s'] ?? 0.0), $rows);
        $avg = count($vals) ? array_sum($vals) / count($vals) : 0.0;
        return ['ok' => true, 'reply' => $this->replyBlocks(sprintf('Average monthly saving (last 3 months): %.2f TND.', $avg), sprintf('Months used: %d', count($vals)), 'Computed from EPARGNE transactions.', 'Use this as baseline for your monthly deposit target.'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    private function createGoalFlow(Connection $conn, int $accId, string $goalAccCol, string $q, array $state): array
    {
        $name = null;
        if (preg_match('/called\s+([a-z0-9 _-]+)/i', $q, $m) === 1) {
            $name = trim((string) $m[1]);
            // Keep only the goal name if the prompt inlines other fields after it
            $name = preg_replace('/\s+(target|montant|amount|tnd|deadline|date|priorite|priority)\b.*$/i', '', $name);
            $name = trim((string) $name);
        }
        $target = $this->extractAmount($q);
        $deadline = $this->extractDate($q);
        $priority = 3;
        if (preg_match('/p([1-5])\b/i', $q, $m) === 1) {
            $priority = (int) $m[1];
        }
        if ($name === null || $name === '') {
            $state['pending'] = ['type' => 'create_goal', 'next_field' => 'name', 'data' => ['target' => $target ?: null, 'deadline' => $deadline, 'priority' => $priority]];
            return ['ok' => false, 'reply' => 'What is the goal name?', 'source' => 'local-action', 'model' => null, 'error' => 'NEED_NAME', 'state' => $state];
        }
        if ($target === null || $target <= 0) {
            $state['pending'] = ['type' => 'create_goal', 'next_field' => 'target', 'data' => ['name' => $name, 'deadline' => $deadline, 'priority' => $priority]];
            return ['ok' => false, 'reply' => 'What is the target amount in TND?', 'source' => 'local-action', 'model' => null, 'error' => 'NEED_TARGET', 'state' => $state];
        }

        $conn->insert('financial_goal', [
            'nom' => $name,
            'montant_cible' => $target,
            'montant_actuel' => 0,
            'date_limite' => $deadline,
            'priorite' => $priority,
            $goalAccCol => $accId,
        ]);
        return ['ok' => true, 'reply' => $this->replyBlocks(sprintf('Goal "%s" created.', $name), sprintf('Target %.2f TND | Deadline %s | Priority P%d', $target, $deadline ?? 'none', $priority), 'Progress starts at 0%.', sprintf('Contribute to start: "contribute 100 TND to %s".', $name)), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    private function renameGoal(Connection $conn, int $accId, string $goalAccCol, string $q, array $state): array
    {
        if (preg_match('/rename goal\s+(.+?)\s+to\s+(.+)$/i', $q, $m) !== 1) {
            return ['ok' => false, 'reply' => 'Use format: rename goal oldname to newname.', 'source' => 'local-action', 'model' => null, 'error' => 'FORMAT', 'state' => $state];
        }
        $old = trim((string) $m[1]);
        $new = trim((string) $m[2]);
        $goal = $this->findGoalByName($this->loadGoals($conn, $accId, $goalAccCol), $old);
        if (!$goal) return ['ok' => false, 'reply' => 'Goal not found.', 'source' => 'local-action', 'model' => null, 'error' => 'NOT_FOUND', 'state' => $state];
        $conn->update('financial_goal', ['nom' => $new], ['id' => (int) $goal['id']]);
        return ['ok' => true, 'reply' => $this->replyBlocks('Goal renamed.', sprintf('"%s" -> "%s"', (string) $goal['nom'], $new), 'References now use the new goal name.', 'Use the new name in future contributions.'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    private function updateGoalTarget(Connection $conn, int $accId, string $goalAccCol, string $q, array $state): array
    {
        $amount = $this->extractAmount($q);
        $goal = $this->findGoalByName($this->loadGoals($conn, $accId, $goalAccCol), $q);
        if (!$goal || $amount === null || $amount <= 0) return ['ok' => false, 'reply' => 'Use format: change <goal> target to <amount>.', 'source' => 'local-action', 'model' => null, 'error' => 'FORMAT', 'state' => $state];
        $newTarget = max((float) $goal['montant_actuel'], $amount);
        $conn->update('financial_goal', ['montant_cible' => $newTarget], ['id' => (int) $goal['id']]);
        return ['ok' => true, 'reply' => $this->replyBlocks('Target updated.', sprintf('Goal %s target is now %.2f TND.', (string) $goal['nom'], $newTarget), sprintf('Remaining: %.2f TND.', max(0.0, $newTarget - (float) $goal['montant_actuel'])), 'Review deadline risk after target change.'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    private function extendGoalDeadline(Connection $conn, int $accId, string $goalAccCol, string $q, array $state): array
    {
        if (preg_match('/by\s+(\d+)\s+month/i', $q, $m) !== 1) return ['ok' => false, 'reply' => 'Use format: extend <goal> deadline by <months> months.', 'source' => 'local-action', 'model' => null, 'error' => 'FORMAT', 'state' => $state];
        $months = max(1, (int) $m[1]);
        $goal = $this->findGoalByName($this->loadGoals($conn, $accId, $goalAccCol), $q);
        if (!$goal || empty($goal['date_limite'])) return ['ok' => false, 'reply' => 'Goal not found or no current deadline set.', 'source' => 'local-action', 'model' => null, 'error' => 'NOT_FOUND', 'state' => $state];
        $new = (new \DateTimeImmutable((string) $goal['date_limite']))->modify('+' . $months . ' month')->format('Y-m-d');
        $conn->update('financial_goal', ['date_limite' => $new], ['id' => (int) $goal['id']]);
        return ['ok' => true, 'reply' => $this->replyBlocks('Deadline extended.', sprintf('Goal %s deadline moved to %s.', (string) $goal['nom'], $new), sprintf('Extension applied: +%d month(s).', $months), 'Re-run what-if to see new confidence.'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    /** @phpstan-ignore-next-line */
    private function setGoalDeadline(Connection $conn, int $accId, string $goalAccCol, string $q, array $state): array
    {
        $date = $this->extractDate($q);
        $goal = $this->findGoalByName($this->loadGoals($conn, $accId, $goalAccCol), $q);
        if (!$goal || $date === null) return ['ok' => false, 'reply' => 'Use format: set <goal> deadline to YYYY-MM-DD.', 'source' => 'local-action', 'model' => null, 'error' => 'FORMAT', 'state' => $state];
        $conn->update('financial_goal', ['date_limite' => $date], ['id' => (int) $goal['id']]);
        return ['ok' => true, 'reply' => $this->replyBlocks('Deadline updated.', sprintf('Goal %s deadline is now %s.', (string) $goal['nom'], $date), 'Timeline risk recalculated on next read.', 'Ask: "which goals are at risk?"'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    /** @phpstan-ignore-next-line */
    private function createAccount(Connection $conn, int $userId, array $accPack, string $q, array $state): array
    {
        $userCol = (string) ($accPack['accUserCol'] ?? 'user_id');
        $balCol = (string) ($accPack['accBalanceCol'] ?? 'sold');
        $amount = $this->extractAmount($q);
        if ($amount === null || $amount < 0) $amount = 0.0;
        $data = [$userCol => $userId, $balCol => $amount];
        if ($this->hasColumn($conn, 'saving_account', 'date_creation')) $data['date_creation'] = date('Y-m-d');
        if ($this->hasColumn($conn, 'saving_account', 'taux_interet')) $data['taux_interet'] = 0;
        if (($name = $this->extractNamedEntity($q, 'called')) !== null) {
            if ($this->hasColumn($conn, 'saving_account', 'nom')) $data['nom'] = $name;
            if ($this->hasColumn($conn, 'saving_account', 'name')) $data['name'] = $name;
        }
        $conn->insert('saving_account', $data);
        return ['ok' => true, 'reply' => $this->replyBlocks('Savings account created.', sprintf('Initial balance %.2f TND.', $amount), 'Account list has been updated.', 'Use "what savings accounts do I have?" to view all.'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    /** @phpstan-ignore-next-line */
    private function renameAccount(Connection $conn, int $userId, array $accPack, string $q, array $state): array
    {
        $nameCol = $this->hasColumn($conn, 'saving_account', 'nom') ? 'nom' : ($this->hasColumn($conn, 'saving_account', 'name') ? 'name' : null);
        if ($nameCol === null) return ['ok' => false, 'reply' => 'Account rename is not supported by current DB schema.', 'source' => 'local-action', 'model' => null, 'error' => 'UNSUPPORTED', 'state' => $state];
        if (preg_match('/rename account\s+(.+?)\s+to\s+(.+)$/i', $q, $m) !== 1) return ['ok' => false, 'reply' => 'Use format: rename account Main to Main Savings.', 'source' => 'local-action', 'model' => null, 'error' => 'FORMAT', 'state' => $state];
        $old = trim((string) $m[1]); $new = trim((string) $m[2]);
        $userCol = (string) ($accPack['accUserCol'] ?? 'user_id');
        $id = $conn->fetchOne("SELECT id FROM saving_account WHERE `$userCol`=:uid AND `$nameCol`=:n LIMIT 1", ['uid' => $userId, 'n' => $old]);
        if (!$id) return ['ok' => false, 'reply' => 'Account not found.', 'source' => 'local-action', 'model' => null, 'error' => 'NOT_FOUND', 'state' => $state];
        $conn->update('saving_account', [$nameCol => $new], ['id' => (int) $id]);
        return ['ok' => true, 'reply' => $this->replyBlocks('Account renamed.', sprintf('"%s" -> "%s"', $old, $new), 'Account label updated.', 'Use the new name for future commands.'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    /** @phpstan-ignore-next-line */
    private function updateAccountBalance(Connection $conn, int $userId, array $accPack, string $q, array $state): array
    {
        $amount = $this->extractAmount($q);
        if ($amount === null || $amount < 0) return ['ok' => false, 'reply' => 'Balance must be a non-negative amount.', 'source' => 'local-action', 'model' => null, 'error' => 'INVALID_AMOUNT', 'state' => $state];
        $id = null;
        if (preg_match('/account\s+#?(\d+)/i', $q, $m) === 1) $id = (int) $m[1];
        if (!$id) $id = (int) ($accPack['accId'] ?? 0);
        $userCol = (string) ($accPack['accUserCol'] ?? 'user_id');
        $balCol = (string) ($accPack['accBalanceCol'] ?? 'sold');
        $conn->executeStatement("UPDATE saving_account SET `$balCol`=:b WHERE id=:id AND `$userCol`=:uid", ['b' => $amount, 'id' => $id, 'uid' => $userId]);
        return ['ok' => true, 'reply' => $this->replyBlocks('Account balance updated.', sprintf('Account #%d balance set to %.2f TND.', $id, $amount), 'Available contribution capacity changed.', 'Check "total savings balance" to confirm aggregate.'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    private function deleteGoalAskConfirm(Connection $conn, int $accId, string $goalAccCol, string $q, array $state): array
    {
        $goal = $this->findGoalByName($this->loadGoals($conn, $accId, $goalAccCol), $q);
        if (!$goal) return ['ok' => false, 'reply' => 'Which goal should I delete?', 'source' => 'local-action', 'model' => null, 'error' => 'NEED_GOAL', 'state' => $state];
        $state['pending'] = ['type' => 'confirm_delete_goal', 'goal_id' => (int) $goal['id']];
        return ['ok' => false, 'reply' => sprintf('Please confirm delete goal "%s" (yes/no).', (string) $goal['nom']), 'source' => 'local-action', 'model' => null, 'error' => 'CONFIRM', 'state' => $state];
    }

    /** @phpstan-ignore-next-line */
    private function deleteAccountAskConfirm(Connection $conn, int $userId, array $accPack, string $q, array $state): array
    {
        $id = null;
        if (preg_match('/account\s+#?(\d+)/i', $q, $m) === 1) $id = (int) $m[1];
        if (!$id) return ['ok' => false, 'reply' => 'Specify account id: delete account #ID', 'source' => 'local-action', 'model' => null, 'error' => 'NEED_ACCOUNT', 'state' => $state];
        $state['pending'] = ['type' => 'confirm_delete_account', 'account_id' => $id];
        return ['ok' => false, 'reply' => sprintf('Please confirm delete account #%d (yes/no).', $id), 'source' => 'local-action', 'model' => null, 'error' => 'CONFIRM', 'state' => $state];
    }

    /** @phpstan-ignore-next-line */
    private function handleHistoryNext(Connection $conn, int $userId, array $state): array
    {
        $page = is_array($state['pagination'] ?? null) ? $state['pagination'] : null;
        if ($page === null) return ['ok' => false, 'reply' => 'No active paginated query. Ask a history search first.', 'source' => 'local-action', 'model' => null, 'error' => 'NO_PAGE', 'state' => $state];
        $criteria = is_array($page['criteria'] ?? null) ? $page['criteria'] : [];
        $offset = (int) ($page['offset'] ?? 0);
        return $this->runHistoryQuery($conn, $userId, $criteria, $offset + 5, $state);
    }

    /** @phpstan-ignore-next-line */
    private function searchHistory(Connection $conn, int $userId, int $accId, string $goalAccCol, string $q, array $state): array
    {
        $criteria = ['keyword' => null, 'from' => null, 'to' => null, 'sort' => 'date_desc', 'contrib_only' => false, 'goal_id' => null];
        if (preg_match('/for\s+[\'"]([^\'"]+)[\'"]/i', $q, $m) === 1) $criteria['keyword'] = trim((string) $m[1]);
        elseif (preg_match('/for\s+([a-z0-9 _-]{2,60})$/i', $q, $m) === 1) $criteria['keyword'] = trim((string) $m[1]);
        if (preg_match('/between\s+(\d{4}-\d{2}-\d{2})\s+and\s+(\d{4}-\d{2}-\d{2})/i', $q, $m) === 1) { $criteria['from'] = $m[1]; $criteria['to'] = $m[2]; }
        if (preg_match('/amount\s+desc/i', $q) === 1) $criteria['sort'] = 'amount_desc';
        if (preg_match('/amount\s+asc/i', $q) === 1) $criteria['sort'] = 'amount_asc';
        if (preg_match('/date\s+asc/i', $q) === 1) $criteria['sort'] = 'date_asc';
        if ($this->containsAny($q, ['filter contributions only', 'contributions only'])) $criteria['contrib_only'] = true;
        if ($this->containsAny($q, ['contributions for goal'])) {
            $goal = $this->findGoalByName($this->loadGoals($conn, $accId, $goalAccCol), $q);
            if ($goal) {
                $criteria['contrib_only'] = true;
                $criteria['goal_id'] = (int) $goal['id'];
            }
        }
        return $this->runHistoryQuery($conn, $userId, $criteria, 0, $state);
    }

    /**
     * @param array<string,mixed> $criteria
     */
    private function runHistoryQuery(Connection $conn, int $userId, array $criteria, int $offset, array $state): array
    {
        $where = "WHERE user_id = :uid AND module_source = :src";
        $params = ['uid' => $userId, 'src' => 'SAVINGS'];
        if (!empty($criteria['keyword'])) {
            $where .= " AND (description LIKE :kw OR type LIKE :kw OR CAST(montant AS CHAR) LIKE :kw)";
            $params['kw'] = '%' . (string) $criteria['keyword'] . '%';
        }
        if (!empty($criteria['from'])) { $where .= " AND DATE(`date`) >= :df"; $params['df'] = (string) $criteria['from']; }
        if (!empty($criteria['to'])) { $where .= " AND DATE(`date`) <= :dt"; $params['dt'] = (string) $criteria['to']; }
        if (!empty($criteria['contrib_only'])) $where .= " AND type = 'GOAL_CONTRIB'";
        if (!empty($criteria['goal_id'])) { $where .= " AND description LIKE :gid"; $params['gid'] = '%#' . (int) $criteria['goal_id'] . '%'; }
        $order = match ((string) ($criteria['sort'] ?? 'date_desc')) {
            'amount_desc' => 'montant DESC, id DESC',
            'amount_asc' => 'montant ASC, id ASC',
            'date_asc' => '`date` ASC, id ASC',
            default => '`date` DESC, id DESC',
        };
        $rows = $conn->fetchAllAssociative("SELECT id,type,montant,`date`,description FROM `transaction` $where ORDER BY $order LIMIT 5 OFFSET " . max(0, $offset), $params);
        $items = array_map(static fn(array $r): string => sprintf('#%d %s %.2f on %s (%s)', (int) $r['id'], (string) $r['type'], (float) $r['montant'], (string) $r['date'], (string) $r['description']), $rows);
        $state['pagination'] = ['criteria' => $criteria, 'offset' => $offset];
        return ['ok' => true, 'reply' => $this->replyBlocks('History query executed.', $items ? implode(' | ', $items) : 'No matching transactions.', 'Showing up to 5 items.', 'Say "next" for more.'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    private function undoLastContribution(Connection $conn, int $userId, array $accPack, string $goalAccCol, array $state): array
    {
        $tx = $conn->fetchAssociative("SELECT id,montant,description FROM `transaction` WHERE user_id=:uid AND module_source=:src AND type='GOAL_CONTRIB' ORDER BY `date` DESC,id DESC LIMIT 1", ['uid' => $userId, 'src' => 'SAVINGS']);
        if (!$tx) return ['ok' => false, 'reply' => 'No contribution transaction found to undo.', 'source' => 'local-action', 'model' => null, 'error' => 'NONE', 'state' => $state];
        $amount = (float) ($tx['montant'] ?? 0.0);
        $goalId = null;
        if (preg_match('/#(\d+)/', (string) ($tx['description'] ?? ''), $m) === 1) $goalId = (int) $m[1];
        $accId = (int) ($accPack['accId'] ?? 0);
        $balCol = (string) ($accPack['accBalanceCol'] ?? 'sold');
        $pkCol = (string) ($accPack['accPkCol'] ?? 'id');
        $userCol = (string) ($accPack['accUserCol'] ?? 'user_id');
        $conn->beginTransaction();
        try {
            if ($goalId !== null) {
                $current = (float) $conn->fetchOne("SELECT montant_actuel FROM financial_goal WHERE id=:gid AND `$goalAccCol`=:acc", ['gid' => $goalId, 'acc' => $accId]);
                $conn->executeStatement("UPDATE financial_goal SET montant_actuel=:v WHERE id=:gid AND `$goalAccCol`=:acc", ['v' => max(0.0, $current - $amount), 'gid' => $goalId, 'acc' => $accId]);
            }
            $conn->executeStatement("UPDATE saving_account SET `$balCol`=`$balCol`+:a WHERE `$pkCol`=:id AND `$userCol`=:uid", ['a' => $amount, 'id' => $accId, 'uid' => $userId]);
            $conn->executeStatement("DELETE FROM `transaction` WHERE id=:id AND user_id=:uid", ['id' => (int) $tx['id'], 'uid' => $userId]);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            return ['ok' => false, 'reply' => 'Undo failed. Please try again.', 'source' => 'local-action', 'model' => null, 'error' => $e->getMessage(), 'state' => $state];
        }
        return ['ok' => true, 'reply' => $this->replyBlocks(sprintf('Undid last contribution of %.2f TND.', $amount), 'Goal and account values were rolled back.', 'Progress and remaining values updated.', 'Ask goal remaining to verify updated status.'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    private function contributeToGoal(Connection $conn, int $userId, array $accPack, string $goalAccCol, string $q, array $state): array
    {
        $amount = $this->extractAmount($q);
        if ($amount === null || $amount <= 0) return ['ok' => false, 'reply' => 'What amount should I contribute (TND)?', 'source' => 'local-action', 'model' => null, 'error' => 'MISSING_AMOUNT', 'state' => $state];
        $accId = (int) ($accPack['accId'] ?? 0);
        $goal = $this->findGoalByName($this->loadGoals($conn, $accId, $goalAccCol), $q);
        if (!$goal) return ['ok' => false, 'reply' => 'Which goal should receive this contribution?', 'source' => 'local-action', 'model' => null, 'error' => 'MISSING_GOAL', 'state' => $state];
        $target = (float) $goal['montant_cible']; $current = (float) $goal['montant_actuel'];
        $remaining = max(0.0, $target - $current);
        if ($remaining <= 0.0) return ['ok' => true, 'reply' => $this->replyBlocks('Goal already complete.', sprintf('"%s" is already at target %.2f TND.', (string) $goal['nom'], $target), 'No additional contribution required.', 'Create a new goal or raise target if needed.'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
        $effective = min($amount, $remaining);
        $balCol = (string) ($accPack['accBalanceCol'] ?? 'sold');
        $pkCol = (string) ($accPack['accPkCol'] ?? 'id');
        $userCol = (string) ($accPack['accUserCol'] ?? 'user_id');
        $balance = (float) $conn->fetchOne("SELECT `$balCol` FROM saving_account WHERE `$pkCol`=:id AND `$userCol`=:uid", ['id' => $accId, 'uid' => $userId]);
        if ($balance < $effective) return ['ok' => false, 'reply' => 'Contribution refused: insufficient savings balance.', 'source' => 'local-action', 'model' => null, 'error' => 'INSUFFICIENT', 'state' => $state];
        $conn->beginTransaction();
        try {
            $conn->executeStatement("UPDATE saving_account SET `$balCol`=`$balCol`-:a WHERE `$pkCol`=:id AND `$userCol`=:uid", ['a' => $effective, 'id' => $accId, 'uid' => $userId]);
            $conn->executeStatement("UPDATE financial_goal SET montant_actuel=montant_actuel+:a WHERE id=:gid AND `$goalAccCol`=:acc", ['a' => $effective, 'gid' => (int) $goal['id'], 'acc' => $accId]);
            $conn->insert('transaction', [
                'type' => 'GOAL_CONTRIB',
                'montant' => $effective,
                'date' => (new \DateTime())->format('Y-m-d H:i:s'),
                'description' => sprintf('Contribution to goal %s (#%d)', (string) $goal['nom'], (int) $goal['id']),
                'module_source' => 'SAVINGS',
                'user_id' => $userId,
            ]);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            return ['ok' => false, 'reply' => 'Contribution failed.', 'source' => 'local-action', 'model' => null, 'error' => $e->getMessage(), 'state' => $state];
        }
        $new = (float) $conn->fetchOne("SELECT montant_actuel FROM financial_goal WHERE id=:gid AND `$goalAccCol`=:acc", ['gid' => (int) $goal['id'], 'acc' => $accId]);
        $newRemaining = max(0.0, $target - $new);
        $progress = $target > 0 ? min(100.0, ($new / $target) * 100.0) : 0.0;
        return ['ok' => true, 'reply' => $this->replyBlocks(sprintf('Contributed %.2f TND to "%s".', $effective, (string) $goal['nom']), sprintf('Current %.2f / %.2f | Remaining %.2f', $new, $target, $newRemaining), sprintf('Progress now %.1f%%.', $progress), 'Set a recurring monthly contribution for consistency.'), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    private function hasColumn(Connection $conn, string $table, string $column): bool
    {
        try {
            $rows = $conn->fetchAllAssociative("SHOW COLUMNS FROM `$table`");
            foreach ($rows as $r) {
                if (strtolower((string) ($r['Field'] ?? '')) === strtolower($column)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
        }
        return false;
    }

    private function extractNamedEntity(string $text, string $keyword): ?string
    {
        if (preg_match('/' . preg_quote($keyword, '/') . '\s+([a-z0-9 _-]{2,60})/i', $text, $m) === 1) {
            return trim((string) $m[1]);
        }
        return null;
    }

    private function normalizeText(string $text): string
    {
        $v = mb_strtolower(trim($text));
        if ($v === '') {
            return '';
        }
        $map = [
            ' au ' => ' how ',
            ' match ' => ' much ',
            ' reminding ' => ' remaining ',
            ' skin care ' => ' skincare ',
            ' dinar ' => ' tnd ',
            ' dt ' => ' tnd ',
            ' girls ' => ' goals ',
            ' girl ' => ' goal ',
            ' goles ' => ' goals ',
            ' saveings ' => ' savings ',
            ' supprimer objectif ' => ' delete goal ',
            ' supprime objectif ' => ' delete goal ',
            ' effacer objectif ' => ' delete goal ',
            ' efface objectif ' => ' delete goal ',
            ' objectif ' => ' goal ',
        ];
        $v = ' ' . $v . ' ';
        foreach ($map as $from => $to) {
            $v = str_replace($from, $to, $v);
        }
        $filler = ['please', 'can you', 'could you', 'i want', 'tell me', 'just', 'kindly'];
        foreach ($filler as $f) {
            $v = str_replace(' ' . $f . ' ', ' ', $v);
        }
        $v = trim((string) (preg_replace('/\s+/', ' ', $v) ?? $v));
        return $v;
    }

    /**
     * @param array<int,array<string,mixed>> $goals
     * @return array{id:string,score:float,canonical:string}
     */
    private function matchToCatalog(string $normalizedText, array $goals): array
    {
        $catalog = [
            ['id' => 'q_goals_list', 'keywords' => ['what', 'goals', 'have'], 'canonical' => 'what goals do i have'],
            ['id' => 'q_goal_remaining', 'keywords' => ['how', 'much', 'remaining'], 'canonical' => 'how much remaining for <goal>'],
            ['id' => 'q_goal_target', 'keywords' => ['target', 'for'], 'canonical' => 'what is the target for <goal>'],
            ['id' => 'q_goal_deadline', 'keywords' => ['deadline', 'for'], 'canonical' => 'what is the deadline for <goal>'],
            ['id' => 'goal_create', 'keywords' => ['create', 'goal', 'called', 'target'], 'canonical' => 'create a goal called <name> target <amount> tnd deadline <date> priority <p>'],
            ['id' => 'goal_rename', 'keywords' => ['rename', 'goal', 'to'], 'canonical' => 'rename goal <old> to <new>'],
            ['id' => 'goal_set_target', 'keywords' => ['set', 'target', 'to'], 'canonical' => 'set <goal> target to <amount>'],
            ['id' => 'goal_extend_deadline', 'keywords' => ['extend', 'deadline', 'months'], 'canonical' => 'extend <goal> deadline by <months> months'],
            ['id' => 'goal_delete', 'keywords' => ['delete', 'goal'], 'canonical' => 'delete goal <goal>'],
            ['id' => 'contribute', 'keywords' => ['contribute', 'tnd', 'to'], 'canonical' => 'contribute <amount> tnd to <goal>'],
            ['id' => 'undo_contribution', 'keywords' => ['undo', 'last', 'contribution'], 'canonical' => 'undo last contribution'],
        ];

        $tokens = array_values(array_filter(explode(' ', $normalizedText), static fn(string $t): bool => $t !== ''));
        $best = ['id' => 'unknown', 'score' => 0.0, 'canonical' => ''];
        foreach ($catalog as $tpl) {
            $hits = 0.0;
            foreach ($tpl['keywords'] as $kw) {
                if (in_array($kw, $tokens, true) || str_contains($normalizedText, $kw)) {
                    $hits += 1.0;
                    continue;
                }
                foreach ($tokens as $t) {
                    if (levenshtein($kw, $t) <= 1) {
                        $hits += 0.6;
                        break;
                    }
                }
            }
            $score = $hits / max(1, count($tpl['keywords']));
            if ($tpl['id'] === 'contribute' && preg_match('/\b(\d+(?:[.,]\d{1,2})?)\b/', $normalizedText) === 1) {
                $score += 0.15;
            }
            if ($score > $best['score']) {
                $best = ['id' => (string) $tpl['id'], 'score' => min(1.0, $score), 'canonical' => (string) $tpl['canonical']];
            }
        }
        return $best;
    }

    /**
     * @param array<int,array<string,mixed>> $goals
     * @return array<string,mixed>
     */
    private function extractEntities(string $normalizedText, array $goals): array
    {
        $goal = $this->findGoalByName($goals, $normalizedText);
        $newName = null;
        if (preg_match('/rename goal\s+.+?\s+to\s+(.+)$/i', $normalizedText, $m) === 1) {
            $newName = trim((string) $m[1]);
        }
        return [
            'goal' => $goal ? (string) ($goal['nom'] ?? '') : null,
            'amount' => $this->extractAmount($normalizedText),
            'date' => $this->extractDate($normalizedText),
            'months' => $this->extractMonths($normalizedText),
            'priority' => $this->extractPriority($normalizedText),
            'new_name' => $newName,
            'name' => $this->extractNamedEntity($normalizedText, 'called'),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function requiredEntitiesForTemplate(string $template): array
    {
        return match ($template) {
            'q_goal_remaining', 'q_goal_target', 'q_goal_deadline', 'goal_delete' => ['goal'],
            'goal_create' => ['name', 'amount', 'date', 'priority'],
            'goal_rename' => ['goal', 'new_name'],
            'goal_set_target' => ['goal', 'amount'],
            'goal_extend_deadline' => ['goal', 'months'],
            'contribute' => ['amount', 'goal'],
            default => [],
        };
    }

    /**
     * @param array<string,mixed> $entities
     * @param array<string,mixed> $accPack
     * @param array<string,mixed> $state
     * @return array{ok:bool,reply:string,source:string,model:?string,error:?string,state:array<string,mixed>}
     */
    private function executeTemplate(
        string $template,
        Connection $conn,
        int $userId,
        array $accPack,
        string $goalAccCol,
        string $q,
        array $state,
        array $entities
    ): array {
        $accId = (int) ($accPack['accId'] ?? 0);
        $qWithGoal = $q;
        if (!empty($entities['goal']) && !str_contains($qWithGoal, (string) $entities['goal'])) {
            $qWithGoal .= ' ' . (string) $entities['goal'];
        }
        return match ($template) {
            'q_goals_list' => $this->answerGoalsList($conn, $accId, $goalAccCol, $state),
            'q_goal_remaining' => $this->answerGoalRemaining($conn, $accId, $goalAccCol, $qWithGoal, $state),
            'q_goal_target' => $this->answerGoalTarget($conn, $accId, $goalAccCol, $qWithGoal, $state),
            'q_goal_deadline' => $this->answerGoalDeadline($conn, $accId, $goalAccCol, $qWithGoal, $state),
            'goal_create' => $this->createGoalFlow($conn, $accId, $goalAccCol, sprintf('create a goal called %s target %s tnd deadline %s priority p%d', (string) $entities['name'], (float) $entities['amount'], (string) $entities['date'], (int) $entities['priority']), $state),
            'goal_rename' => $this->renameGoal($conn, $accId, $goalAccCol, sprintf('rename goal %s to %s', (string) $entities['goal'], (string) $entities['new_name']), $state),
            'goal_set_target' => $this->updateGoalTarget($conn, $accId, $goalAccCol, sprintf('set %s target to %s', (string) $entities['goal'], (float) $entities['amount']), $state),
            'goal_extend_deadline' => $this->extendGoalDeadline($conn, $accId, $goalAccCol, sprintf('extend %s deadline by %d months', (string) $entities['goal'], (int) $entities['months']), $state),
            'goal_delete' => $this->deleteGoalAskConfirm($conn, $accId, $goalAccCol, sprintf('delete goal %s', (string) $entities['goal']), $state),
            'contribute' => $this->contributeToGoal($conn, $userId, $accPack, $goalAccCol, sprintf('contribute %s tnd to %s', (float) $entities['amount'], (string) $entities['goal']), $state),
            'undo_contribution' => $this->askUndoConfirmation($state),
            default => ['ok' => false, 'reply' => 'Unknown command. Type /help.', 'source' => 'local-action', 'model' => null, 'error' => 'UNKNOWN', 'state' => $state],
        };
    }

    private function askUndoConfirmation(array $state): array
    {
        $state['pending'] = ['type' => 'confirm_undo_contribution'];
        return ['ok' => false, 'reply' => 'Confirm undo last contribution? (yes/no)', 'source' => 'local-action', 'model' => null, 'error' => 'CONFIRM', 'state' => $state];
    }

    private function extractMonths(string $text): ?int
    {
        if (preg_match('/\b(\d+)\s*months?\b/i', $text, $m) === 1) {
            return (int) $m[1];
        }
        $words = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6];
        foreach ($words as $w => $n) {
            if (preg_match('/\b' . $w . '\s*months?\b/i', $text) === 1) {
                return $n;
            }
        }
        return null;
    }

    private function extractPriority(string $text): ?int
    {
        if (preg_match('/\bp\s*([1-4])\b/i', $text, $m) === 1) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $history
     * @return array{used:bool,canonical:?string,model:?string}
     */
    private function rewriteCommandWithAi(string $message, array $history): array
    {
        $apiKey = $this->env('OPENAI_API_KEY');
        if ($apiKey === '') {
            return ['used' => false, 'canonical' => null, 'model' => null];
        }
        $model = $this->env('OPENAI_MODEL', 'gpt-4o-mini');
        $endpoint = $this->env('OPENAI_ENDPOINT', 'https://api.openai.com/v1/chat/completions');

        $payload = [
            'model' => $model,
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You normalize user commands for a Savings & Goals assistant.\n"
                        . "Keep names, amounts, and dates exactly.\n"
                        . "Fix speech-to-text errors like girls->goals.\n"
                        . "Return strict JSON only: {\"canonical\":\"...\"}\n"
                        . "Do not add explanations.",
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'user_message' => $message,
                        'history_tail' => array_slice($history, -4),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
        ];

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 12,
            ]);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                return ['used' => false, 'canonical' => null, 'model' => $model];
            }
            $data = $response->toArray(false);
            $raw = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
            if ($raw === '') {
                return ['used' => false, 'canonical' => null, 'model' => $model];
            }
            $json = json_decode($raw, true);
            if (!is_array($json)) {
                return ['used' => false, 'canonical' => null, 'model' => $model];
            }
            $canonical = trim((string) ($json['canonical'] ?? ''));
            if ($canonical === '') {
                return ['used' => false, 'canonical' => null, 'model' => $model];
            }
            return ['used' => true, 'canonical' => $canonical, 'model' => $model];
        } catch (\Throwable $e) {
            return ['used' => false, 'canonical' => null, 'model' => $model];
        }
    }

    /** @phpstan-ignore-next-line */
    private function answerGoalsStartingWith(Connection $conn, int $accId, string $goalAccCol, string $q, array $state): array
    {
        $letter = null;
        if (preg_match('/starting with\s+([a-z0-9])/i', $q, $m) === 1) {
            $letter = strtolower((string) $m[1]);
        }
        if ($letter === null) {
            return ['ok' => false, 'reply' => 'Which starting letter should I use? Example: "search for goals starting with s".', 'source' => 'local-action', 'model' => null, 'error' => 'MISSING_LETTER', 'state' => $state];
        }
        $rows = $conn->fetchAllAssociative(
            "SELECT nom, montant_cible, montant_actuel, date_limite, priorite
             FROM financial_goal
             WHERE `$goalAccCol` = :acc AND LOWER(nom) LIKE :prefix
             ORDER BY nom ASC",
            ['acc' => $accId, 'prefix' => $letter . '%']
        );
        if (!$rows) {
            return ['ok' => true, 'reply' => $this->replyBlocks(
                sprintf('No goals start with "%s".', strtoupper($letter)),
                '0 matching goals.',
                'No data impact.',
                'Try another letter or ask "what goals do I have?".'
            ), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
        }
        $items = [];
        foreach (array_slice($rows, 0, 8) as $g) {
            $remaining = max(0.0, (float) $g['montant_cible'] - (float) $g['montant_actuel']);
            $items[] = sprintf('%s (remaining %.2f TND, deadline %s, P%d)', (string) $g['nom'], $remaining, (string) ($g['date_limite'] ?? 'none'), (int) ($g['priorite'] ?? 3));
        }
        return ['ok' => true, 'reply' => $this->replyBlocks(
            sprintf('Found %d goal(s) starting with "%s".', count($rows), strtoupper($letter)),
            implode(' | ', $items),
            'Filtered from your current goals.',
            'Say "search for goals starting with <letter>" for another filter.'
        ), 'source' => 'local-action', 'model' => null, 'error' => null, 'state' => $state];
    }

    public function chat(array $dbContext, string $question, array $history = []): array
    {
        $question = trim($question);
        if ($question === '') {
            return [
                'ok' => false,
                'source' => 'fallback',
                'reply' => 'Please enter a question.',
                'error' => 'EMPTY_QUESTION',
                'model' => null,
            ];
        }

        $apiKey = $this->env('OPENAI_API_KEY');
        $model = $this->env('OPENAI_MODEL', 'gpt-4o-mini');
        $endpoint = $this->env('OPENAI_ENDPOINT', 'https://api.openai.com/v1/chat/completions');
        $mode = $this->classifyQuestion($question);
        $contextDigest = $this->compactDbDigest($dbContext);

        if ($apiKey === '') {
            $fallback = $this->buildFallbackReply($dbContext, $question, $history);
            return [
                'ok' => true,
                'source' => 'fallback',
                'reply' => $fallback,
                'error' => 'MISSING_OPENAI_API_KEY',
                'model' => null,
            ];
        }

        $style = $this->pick([
            'Be clear and strategic with concise bullets.',
            'Be practical with concrete steps and numbers when available.',
            'Be analytical and scenario-driven.',
            'Be coaching-oriented and action-focused.',
        ]);

        $messages = [
            [
                'role' => 'system',
                'content' => "You are Decide$ Smart Financial Assistant.\n"
                    . "You are an open-domain assistant with strong DB-awareness for this application.\n"
                    . "You can answer any topic, and you must be excellent at understanding reformulated user questions.\n"
                    . "Rules:\n"
                    . "- First infer intent mode: app-data, generic, or mixed.\n"
                    . "- Internally rewrite the user question into a canonical intent before answering.\n"
                    . "- Treat paraphrases as equivalent (same meaning, different wording).\n"
                    . "- For app-data mode: answer directly from provided DB context and include concrete numbers.\n"
                    . "- For generic mode: answer normally on the asked topic and do not force DB numbers.\n"
                    . "- For mixed mode: combine both sections (DB-based + general advice).\n"
                    . "- Never invent exact user data not present in context.\n"
                    . "- If the user asks about 'my goals/my data/my app', always use DB context first.\n"
                    . "- Never refuse only because the topic is non-financial.\n"
                    . "- Match the user's language when possible (French/English/Arabic).\n"
                    . "- Give concrete steps and avoid repeating same phrasing.\n"
                    . "- If asked for real-time data you do not have, state that limitation clearly and provide best-effort guidance.\n"
                    . "- {$style}\n"
                    . "Response format:\n"
                    . "1) Short answer\n"
                    . "2) Action steps (2-4 bullets)\n"
                    . "3) If needed, assumptions/scope line\n"
                    . "Inferred mode: {$mode}\n"
                    . "DB context JSON:\n"
                    . json_encode($contextDigest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];

        foreach (array_slice($history, -6) as $msg) {
            $role = (string) ($msg['role'] ?? '');
            $content = trim((string) ($msg['content'] ?? ''));
            if ($content === '' || !in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $messages[] = ['role' => $role, 'content' => $content];
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        $payload = [
            'model' => $model,
            'temperature' => 0.35,
            'top_p' => 0.95,
            'messages' => $messages,
        ];

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 25,
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $fallback = $this->buildFallbackReply($dbContext, $question, $history);
            return [
                'ok' => true,
                'source' => 'fallback',
                'reply' => $fallback,
                'error' => $e->getMessage(),
                'model' => $model,
            ];
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $fallback = $this->buildFallbackReply($dbContext, $question, $history);
            return [
                'ok' => true,
                'source' => 'fallback',
                'reply' => $fallback,
                'error' => (string) ($data['error']['message'] ?? ('HTTP ' . $status)),
                'model' => $model,
            ];
        }

        $text = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
        if ($text === '') {
            $fallback = $this->buildFallbackReply($dbContext, $question, $history);
            return [
                'ok' => true,
                'source' => 'fallback',
                'reply' => $fallback,
                'error' => 'EMPTY_REPLY',
                'model' => $model,
            ];
        }

        return [
            'ok' => true,
            'source' => 'openai',
            'reply' => $text,
            'error' => null,
            'model' => $model,
        ];
    }
}
