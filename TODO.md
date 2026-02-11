# TODO: Améliorations du Projet Symfony

## 1. Contrôle de Saisie sur Tous les Formulaires
- [x] Add des contraintes de validation (NotBlank, Length, Range, Email, etc.) à TransactionType.php
- [x] Add des contraintes de validation à ExpenseType.php
- [x] Add des contraintes de validation à UserType.php
- [x] Add des contraintes de validation à FinancialGoalType.php
- [x] Add des contraintes de validation à SavingAccountRateType.php
- [x] Add des contraintes de validation à RevenueType.php
- [x] Add des contraintes de validation à QuizType.php
- [x] Add des contraintes de validation à CourseType.php
- [x] Add des contraintes de validation à CasRellesType.php

## 2. Amélioration de la Page Transaction (Back/Admin)
- [ ] Edit templates/admin/transactions.html.twig pour ajouter filtres de recherche, tableau responsive avec pagination
- [ ] Update TransactionController.php pour gérer les filtres et la pagination
- [ ] Add styles CSS pour responsive design dans public/css/style_1.css ou nouveau fichier

## 3. Sidebar Commune pour Pages Goals (Back)
- [ ] Create un layout commun pour goals admin (ex. templates/admin/goals_layout.html.twig) basé sur admin/layout.html.twig
- [ ] Edit templates/goals/index.html.twig pour utiliser le nouveau layout avec sidebar
- [ ] Edit autres templates goals (show.html.twig, etc.) pour utiliser le layout commun

## 4. Tests et Vérifications
- [ ] Tester tous les formulaires pour valider les contraintes
- [ ] Tester la page transaction améliorée
- [ ] Tester la sidebar sur les pages goals

