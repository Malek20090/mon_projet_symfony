# Certification System Plan

## Summary
The student wants to enable students to obtain certifications in:
1. Investment and Money Management domain
2. Crypto domain

Using free certification APIs.

## Analysis of Current System
- ✅ Quiz system exists (src/Controller/StudentController.php)
- ✅ Quiz passing interface (templates/student/quiz/passer.html.twig)
- ✅ Crypto API integrated (CoinGecko - free)
- ✅ 8 educational courses already exist in SQL
- ✅ Quiz questions already exist for all courses

## Available Free Certification Options

### Option A: Internal Certification System (Recommended - Built-in)
Create certification tracks within existing quiz system:
- **Finance Basics Certification**: Complete quizzes on courses 1,3,4,8
- **Investment Certification**: Complete quizzes on courses 2,6
- **Crypto Certification**: Complete quizzes on course 5
- Generate PDF certificates upon completion

### Option B: External Free Certifications (Integration)
| Provider | Type | Free Tier | API |
|----------|------|-----------|-----|
| Binance Academy | Crypto Education | ✅ Free courses | ❌ No cert API |
| Coingecko | Crypto Data | ✅ Free API | ✅ (already integrated) |
| Open Certs | Blockchain Certs | ✅ Limited | ❌ No public API |
| Credly/Acclaim | Digital Badges | ✅ Some free | ❌ Enterprise only |

### Recommendation: Option A (Internal System)
This is the most feasible and controllable approach:
1. Create "Certification Tracks" that group related quizzes
2. Set minimum score thresholds to pass
3. Generate PDF certificates
4. Track progress toward certifications

## Implementation Plan

### Phase 1: Database & Entity Updates
- [ ] Create `Certification` entity (name, description, required_cours_ids, min_score)
- [ ] Create `CertificationAttempt` entity (user, certification, score, passed, date)
- [ ] Update SQL with certification data

### Phase 2: Admin Interface
- [ ] Add certification management in admin panel
- [ ] Assign courses to certifications
- [ ] View student certification progress

### Phase 3: Student Interface
- [ ] Show available certifications on student dashboard
- [ ] Display certification progress (% completed)
- [ ] Allow taking certification exams (series of quizzes)
- [ ] Generate PDF certificates for passed certifications

### Phase 4: External API Integration (Optional Enhancement)
- [ ] Integrate CoinGecko for live crypto quizzes
- [ ] Add "Live Crypto Quiz" feature with real-time prices

## Next Steps
Wait for user confirmation to proceed with this plan.
