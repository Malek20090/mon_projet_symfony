# Salary/Expense Model Documentation

**Overview**
This document describes the Salary/Expense model features implemented in the module, where the logic lives, and how each feature is wired.

**Chart.js**
Purpose: Display revenue/expense charts (dashboard view).
Implementation:
- Controller: `src/Controller/DashboardController.php` builds Chart.js datasets via Symfony UX ChartJS.
- Template: `templates/dashboard/index.html.twig` renders charts.
Salary/Expense link:
- Template: `templates/salary_expense/index.html.twig` buttons link to `app_dashboard` with `focus=revenues|expenses`.

**Suggest Category**
Purpose: Suggest an expense category based on description and amount.
Implementation:
- Endpoint: `src/Controller/SalaryExpenseController.php` method `suggestExpenseCategory()` (`/salary-expense/expense/suggest-category`).
- Service: `src/Service/ExpenseCategorySuggestionService.php` (category inference).
- Template: `templates/salary_expense/index.html.twig` uses JS fetch to call endpoint.

**Recurring Transaction Engine**
Purpose: Detect recurring patterns and allow one-click conversion into rules.
Implementation:
- Pattern detection: `src/Service/RecurringPatternService.php` method `buildSuggestions()`.
- Controller: `src/Controller/SalaryExpenseController.php` builds `recurringSuggestions` and loads active rules.
- Rule creation: `src/Controller/SalaryExpenseController.php` method `acceptRecurringSuggestion()` (`app_salary_expense_recurring_accept`).
- UI: `templates/salary_expense/index.html.twig` recurring cards and convert form.
- Scheduler: `src/Command/GenerateRecurringTransactionsCommand.php`.

**Monthly KPI Totals**
Purpose: KPI totals for income and expenses filtered by selected month (independent selectors).
Implementation:
- Controller: `src/Controller/SalaryExpenseController.php`
  - Query params: `totals_month_revenue`, `totals_month_expense`
  - Computed: `monthlyTotalIncome`, `monthlyTotalExpenses`, `monthlyNetBalance`
  - Options: `totalsRevenueMonths`, `totalsExpenseMonths`, plus `all` option
- UI: `templates/salary_expense/index.html.twig` KPI selectors and JS reload.

**Advanced Expense Statistics**
Purpose: Monthly totals, evolution, averages, and top categories.
Implementation:
- Service: `src/Service/ExpenseStatisticsService.php` method `build()`.
- Controller: `src/Controller/SalaryExpenseController.php` calls `build()` and passes `expenseStats`.
- UI: `templates/salary_expense/index.html.twig` statistics section.

**AI Monthly Advice**
Purpose: Generate monthly advice for expenses.
Implementation:
- Service: `src/Service/SalaryExpenseAiService.php`
  - `buildMonthlyExpenseAdvice()` uses AI (Gemini) when API key exists.
  - Falls back to local rules if AI is unavailable.
- Controller: `src/Controller/SalaryExpenseController.php` calls `buildMonthlyExpenseAdvice()`.
- UI: `templates/salary_expense/index.html.twig` displays advice.

**Anomaly Detection (Anomalous Expenses)**
Purpose: Flag unusually high expenses based on statistical learning.
Implementation:
- Repository stats: `src/Repository/ExpenseRepository.php` method `getExpenseStats()`.
- AI service: `src/Service/ExpenseAnomalyAIService.php`
  - `isAnomalousExpense(Expense $expense): bool`
  - `anomalyScore(Expense $expense): float`
  - Uses overall and category baselines, sensitivity, and minimum amount threshold.
- Monitor service: `src/Service/ExpenseAnomalyMonitorService.php` method `handleNewExpense()`.
- Controller: `src/Controller/SalaryExpenseController.php`
  - Calls monitor after saving expense.
  - Builds `anomalousExpenseIds` for UI badge.
- UI: `templates/salary_expense/index.html.twig` shows badge in expense table.
Configuration:
- `config/services.yaml`
  - `expense_anomaly_sensitivity` (default 2.5)
  - `expense_anomaly_min_amount` (default 400)

**Mailer Alerts**
Purpose: Notify users when expense ratio is high or for monthly summary.
Implementation:
- Service: `src/Service/FinancialMonitoringService.php`
  - `evaluateAndNotify()` decides if alerts should be sent.
- Mailer: `src/Service/FinancialAlertMailerService.php`
  - Sends overspending alert and monthly summary.
- Controller: `src/Controller/SalaryExpenseController.php`
  - Calls `evaluateAndNotify()` after saving revenue/expense.

**AI Usage Summary**
External AI (Google Gemini):
- Service: `src/Service/SalaryExpenseAiService.php`
  - Methods: `generateAiSummary()`, `generateMonthlyAdviceWithAi()`, `requestGeminiText()`
  - Config: `config/services.yaml` for API key and model.

Local AI (statistical):
- Service: `src/Service/ExpenseAnomalyAIService.php`
  - Pure backend logic using database statistics.

