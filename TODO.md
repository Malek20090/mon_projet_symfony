# TODO: Enhance Student Interfaces

## templates/student/cours/index.html.twig
- [x] Add hero section with gradient background, animated title, and descriptive text
- [x] Enhance card design: Add fade-in animations, improved shadows, rounded corners
- [x] Refine colors: Use more vibrant blues and better contrast
- [x] Improve empty state: Add animated icon and call-to-action button
- [x] Add responsive improvements: Better mobile spacing and layout
- [x] Enhance animations: Add staggered animations, bounce, zoom effects, and custom floating keyframes
- [x] Improve visual appeal: Add background patterns, glowing hover effects, and enhanced gradients for better visibility

## templates/student/quiz/index.html.twig
- [x] Add hero section tailored for quizzes with gradient background and animations
- [x] Enhance card design: Include animations, better badge styling, star ratings
- [x] Refine colors: Use green accents with improved gradients and shadows
- [x] Improve empty state: Add animated elements and navigation links
- [x] Add responsive improvements: Ensure cards stack nicely on smaller screens
- [x] Enhance animations: Add staggered animations, bounce, zoom effects, and custom floating keyframes
- [x] Improve visual appeal: Add background patterns, glowing hover effects, and enhanced gradients for better visibility

## public/js/main.js
- [x] Add custom JavaScript: Implement scroll-triggered animations, interactive hover effects, and loading animations

## Followup Steps
- [x] Test pages visually by running Symfony app and navigating to student sections
- [x] Verify responsiveness on different screen sizes
- [x] Check for console errors or styling conflicts
- [ ] Test enhanced animations and visual improvements
- [ ] Verify performance and loading times

## Phase 1 - Entity Updates ✅ COMPLETED
- [x] 1.1 Add `raisonRefus` field to CasRelles entity for rejection reason
- [x] 1.2 Add `raison` field to CasRelles for rejection reason storage

## Phase 2 - Controller Updates ✅ COMPLETED

### ImprevusController
- [x] 2.1 Add route to submit CasRelles request (POST)
- [x] 2.2 Get all savings accounts for display in template
- [x] 2.3 Handle (+): add to savings OR security fund
- [x] 2.4 Handle (-): take from savings, security fund, OR family

### AdminController  
- [x] 2.5 Add route to process/accept request with validation
- [x] 2.6 Add route to reject request with reason
- [x] 2.7 Add security fund balance management (single global value)

## Phase 3 - Template Updates ✅ COMPLETED

### alea/index.html.twig
- [x] 3.1 For (+): Show savings accounts list with balance & date
- [x] 3.2 Add option for security fund (starts at 0, increases)
- [x] 3.3 Create form to submit the request

### admin/casrelles/process.html.twig
- [x] 3.4 Add accept/reject functionality
- [x] 3.5 Add rejection modal with reason input

### admin/casrelles/list.html.twig
- [x] 3.6 Show pending requests with action buttons
- [x] 3.7 Add modal for rejection reason

## Phase 4 - JavaScript & Validation ✅ COMPLETED
- [x] 4.1 Add savings account selection with balance/date display
- [x] 4.2 Add validation for sufficient balance
- [x] 4.3 Add rejection popup/modal functionality

## Phase 5 - Database Migration
- [ ] 5.1 Run doctrine:migration to add raisonRefus column
- [ ] 5.2 Clear cache

## Phase 6 - Testing
- [ ] 6.1 Test (+) case - add to savings
- [ ] 6.2 Test (+) case - add to security fund
- [ ] 6.3 Test (-) case - take from savings (sufficient balance)
- [ ] 6.4 Test (-) case - take from savings (insufficient balance)
- [ ] 6.5 Test (-) case - use security fund
- [ ] 6.6 Test (-) case - family option
- [ ] 6.7 Test admin accept/reject functionality
