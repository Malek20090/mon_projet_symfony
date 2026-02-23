<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

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
        $goalName = (string) ($dbContext['topGoal']['name'] ?? 'my top goal');
        $riskCount = (int) ($dbContext['goalsAtRisk'] ?? 0);
        $balance = (float) ($dbContext['balanceNow'] ?? 0);

        $setA = [
            "How can I fund {$goalName} faster without increasing risk?",
            "Give me a 30-day plan to reduce expenses and boost savings.",
            "What monthly amount should I target to cut my completion time by half?",
            "Which goal should I postpone first if cashflow stays tight?",
            "How can I create +300 TND extra income this month?",
        ];

        $setB = [
            "Explain my top 3 financial bottlenecks from current data.",
            "Build a conservative vs aggressive plan for my goals.",
            "What should I automate weekly to improve discipline?",
            "Give me a brutal truth version of my current plan.",
            "If I do nothing for 12 months, what happens?",
        ];

        $setC = [
            "How should I split money across emergency fund, goals, and investment?",
            "Can you design a debt-safe strategy and a growth strategy?",
            "How to protect against inflation while saving for goals?",
            "Give me a student-friendly plan and a salary-friendly plan.",
            "What habits are probably hurting my progress?",
        ];

        $setD = [
            "Explain this code error and suggest a clean fix.",
            "Help me write a professional email in French and English.",
            "Give me a step-by-step explanation of machine learning basics.",
            "Translate a message for me and keep the same tone.",
            "Help me prepare for an interview with 10 likely questions.",
        ];

        $pool = array_merge($setA, $setB, $setC, $setD);
        shuffle($pool);

        $extra = $riskCount > 0
            ? "I have {$riskCount} goals at risk. What should I cut first?"
            : "My current balance is " . number_format($balance, 2, '.', '') . " TND. How should I allocate it now?";

        array_unshift($pool, $extra);
        return array_slice(array_values(array_unique($pool)), 0, 6);
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
