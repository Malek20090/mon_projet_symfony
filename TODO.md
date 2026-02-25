# Decide$ - Project TODO

## Features Implemented

### ✅ Certification System (QuizAPI Integration)
- [x] Created QuizApiService for fetching quizzes from QuizAPI
- [x] Created CertificationController with routes:
  - `/certification` - List available certifications
  - `/certification/{type}` - Start certification quiz
  - `/certification/{type}/answer` - Submit answer
  - `/certification/{type}/result` - View results
  - `/certification/{type}/certificate` - View/download certificate
- [x] Created templates:
  - `certification/index.html.twig` - Certification list page
  - `certification/quiz.html.twig` - Quiz taking interface
  - `certification/result.html.twig` - Results page
  - `certification/certificate.html.twig` - Certificate template
- [x] Added security access control for certification routes
- [x] Added Certifications link to student navigation menu

### QuizAPI Integration Details
- Uses QuizAPI (https://quizapi.io/) - Free tier available
- Fallback to default quiz questions if API fails
- Categories: Crypto, Finance, Banking
- Cache system for API responses

### How to Use
1. Students log in with ROLE_ETUDIANT
2. Navigate to "Certifications" in the menu
3. Choose certification (Crypto or Finance)
4. Answer 10 questions
5. Need 70% to pass
6. Download certificate if successful

### API Key Setup
To enable QuizAPI, add your API key in:
`src/Service/QuizApiService.php`

Get a free key at: https://quizapi.io/

## Current Status
The certification system is ready and functional with fallback quiz questions.
