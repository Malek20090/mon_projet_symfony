-- Inserting Courses (Cours) into the database

-- Cours 1: Introduction à la Finance Personnelle
INSERT INTO cours (titre, contenu_texte, type_media, url_media, user_id) 
VALUES (
    'Introduction à la Finance Personnelle',
    'La finance personnelle est la gestion des ressources financières d un individu ou d un ménage. Elle comprend l établissement d un budget, l épargne, les investissements et la planification de la retraite.

Les principes fondamentaux de la finance personnelle:
1. Connaître sa situation financière actuelle
2. Établir des objectifs financiers réalistes
3. Créer et suivre un budget
4. Épargner régulièrement
5. Investir intelligemment
6. Protéger ses biens et revenus

La finance personnelle est essentielle pour atteindre l indépendance financière et vivre sereinement.',
    'texte',
    NULL,
    1
);

-- Cours 2: Les Bases de l'Investissement
INSERT INTO cours (titre, contenu_texte, type_media, url_media, user_id) 
VALUES (
    'Les Bases de l Investissement',
    'L investissement consiste à placer de l argent dans l espoir de générer des rendements futurs. Voici les concepts essentiels:

1. Le risque et le rendement: Plus le potentiel de gain est élevé, plus le risque l est aussi.
2. La diversification: Ne pas mettre tous ses œufs dans le même panier.
3. L horizon temporel: La durée pendant laquelle vous pouvez garder votre argent investi.
4. Les différents types d investissements: Actions, obligations, fonds communs de placement, immobilier, etc.

Comprendre ces bases vous aidera à prendre de meilleures décisions financières.',
    'texte',
    NULL,
    1
);

-- Cours 3: Budget et Épargne
INSERT INTO cours (titre, contenu_texte, type_media, url_media, user_id) 
VALUES (
    'Comment Créer un Budget Efficace',
    'Un budget est un outil essentiel pour gérer vos finances personnelles. Voici les étapes pour créer un budget efficace:

1. Calculer vos revenus mensuels nets
2. Lister toutes vos dépenses fixes (loyer, factures, prêt)
3. Identifier vos dépenses variables (courses, loisirs)
4. Appliquer la règle 50/30/20: 50% besoins, 30% envies, 20% épargne
5. Suivre et ajuster régulièrement

L épargne est cruciale pour faire face aux imprévus et atteindre vos objectifs financiers à long terme.',
    'texte',
    NULL,
    1
);

-- Cours 4: Gestion des Dépenses
INSERT INTO cours (titre, contenu_texte, type_media, url_media, user_id) 
VALUES (
    'Maîtriser la Gestion de vos Dépenses',
    'La gestion des dépenses est primordiale pour maintenir une santé financière équilibrée. Points clés:

1. Distinguer les besoins des envies
2. Éviter les achats impulsifs
3. Utiliser le paiement en espèces pour les petits montants
4. Analyser vos relevés bancaires mensuellement
5. Fixer des limites de dépenses par catégorie
6. Utiliser des applications de suivi budgétaire

Une bonne gestion des dépenses vous permettra d épargner davantage et d atteindre vos objectifs financiers.',
    'texte',
    NULL,
    1
);

-- Cours 5: Introduction aux Cryptomonnaies
INSERT INTO cours (titre, contenu_texte, type_media, url_media, user_id) 
VALUES (
    'Introduction aux Cryptomonnaies',
    'Les cryptomonnaies sont des devises numériques décentralisées basées sur la technologie blockchain. Aspects essentiels:

1. Bitcoin: La première et plus connue des cryptomonnaies
2. Blockchain: Technologie de registre distribué
3. Wallet: Portefeuille numérique pour stocker vos cryptos
4. Volatilité: Les prix peuvent fluctuer considérablement
5. Regulation: Le cadre légal varie selon les pays

Attention: Les investissements en cryptomonnaies sont risqués. Faites toujours vos propres recherches avant d investir.',
    'texte',
    NULL,
    1
);

-- Cours 6: Planification de la Retraite
INSERT INTO cours (titre, contenu_texte, type_media, url_media, user_id) 
VALUES (
    'Planifier sa Retraite dès Maintenant',
    'Il n est jamais trop tôt pour commencer à planifier sa retraite. Voici pourquoi et comment:

1. L importance de commencer tôt: Les intérêts composés font wonders sur le long terme
2. Les différents produits d épargne retraite: PER, assurance vie, plan d épargne retraite
3. Évaluer vos besoins futurs: Calculez le montant dont vous aurez besoin
4. Diversifier vos sources de revenus: Ne comptez pas uniquement sur la retraite publique
5. Réviser régulièrement votre plan: Adaptez-vous aux changements de vie

Commencez dès aujourd hui pour garantir une retraite paisible.',
    'texte',
    NULL,
    1
);

-- Cours 7: Gestion des имprevus (Urgences)
INSERT INTO cours (titre, contenu_texte, type_media, url_media, user_id) 
VALUES (
    'Comment Créer un Fond d Urgence',
    'Un fonds d urgence est essentiel pour faire face aux imprévus financiers. Guide pratique:

1. Objectif: Épargner 3 à 6 mois de dépenses
2.where to keep it: Compte épargne accessible, pas en bourse
3. when to l utiliser: Perte d emploi, réparations urgentes, médicales
4. how to start: Mettre en place des virements automatiques
5. rebuilding: Si vous utilisez le fonds, reconstituez-le rapidement

Un fonds d urgence vous apporte la paix mentale et vous évite de vous endetter en cas de coup dur.',
    'texte',
    NULL,
    1
);

-- Cours 8: Psychologie de l'Argent
INSERT INTO cours (titre, contenu_texte, type_media, url_media, user_id) 
VALUES (
    'La Psychologie de l Argent',
    'L argent a une dimension psychologique importante. Comprendre vos comportements financiers:

1. Bias cognitifs: Comment nos décisions financières sont influencées
2. Émotion et argent: La peur et la cupidité en investissement
3. Mentalité d abondance vs rareté
4. L influence sociale sur nos dépenses
5. Comment overcome les mauvaises habitudes financières

La connaissance de ces aspects vous aidera à prendre de meilleures décisions financières.',
    'texte',
    NULL,
    1
);

-- ============ QUIZZES ============

-- Quiz pour Cours 1: Finance Personnelle
INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'Quel est le premier pas dans la gestion de la finance personnelle?',
    '["Épargner de l\'argent", "Connaître sa situation financière actuelle", "Investir en bourse", "Acheter des biens"]',
    'Connaître sa situation financière actuelle',
    10,
    1,
    1
);

INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'La finance personnelle inclut uniquement la gestion du budget.',
    '["Vrai", "Faux"]',
    'Faux',
    5,
    1,
    1
);

INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'Quels sont les principes fondamentaux de la finance personnelle?',
    '["Établir un budget et épargner", "Investir sans connaissance", "Ignorer les dépenses", "Ne pas planifier la retraite"]',
    'Établir un budget et épargner',
    10,
    1,
    1
);

-- Quiz pour Cours 2: Investissement
INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'Quelle est la relation entre risque et rendement?',
    '["Le risque n\'affecte pas le rendement", "Plus le risque est élevé, plus le potentiel de rendement est élevé", "Le rendement est toujours garanti", "Le risque diminuje le rendement"]',
    'Plus le risque est élevé, plus le potentiel de rendement est élevé',
    10,
    2,
    1
);

INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'Que signifie diversifier ses investissements?',
    '["Mettre tout son argent dans une seule action", "Répartir ses investissements sur plusieurs actifs", "Acheter uniquement des obligations", "Éviter d\'investir"]',
    'Répartir ses investissements sur plusieurs actifs',
    10,
    2,
    1
);

INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'L horizon temporel concerne:',
    '["Le moment de la journée pour investir", "La durée pendant laquelle vous pouvez garder votre argent investi", "L\'âge de l\'investisseur", "Le temps de réaction du marché"]',
    'La durée pendant laquelle vous pouvez garder votre argent investi',
    10,
    2,
    1
);

-- Quiz pour Cours 3: Budget et Épargne
INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'À quoi correspond la règle 50/30/20?',
    '["50% impôts, 30% dépenses, 20% épargne", "50% besoins, 30% envies, 20% épargne", "50% épargne, 30% besoins, 20% dettes", "50% revenus, 30% investissements, 20% dépenses"]',
    '50% besoins, 30% envies, 20% épargne',
    10,
    3,
    1
);

INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'Quelles sont les dépenses fixes dans un budget?',
    '["Courses alimentaires", "Loyer et factures mensuelles", "Loisirs et divertissements", "Achats impulsifs"]',
    'Loyer et factures mensuelles',
    10,
    3,
    1
);

INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'Pourquoi est-il important de suivre son budget régulièrement?',
    '["Pour le plaisir de compter", "Car les dépenses changent souvent, il faut ajuster", "C\'est obligatoire par la loi", "Pour impressionner ses amis"]',
    'Car les dépenses changent souvent, il faut ajuster',
    10,
    3,
    1
);

-- Quiz pour Cours 4: Gestion des Dépenses
INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'Quelle est la meilleure façon d éviter les achats impulsifs?',
    '["Acheter immédiatement ce qui nous fait envie", "Faire une liste de courses et s\'y tenir", "Utiliser toujours sa carte de crédit", "Acheter en ligne sans réfléchir"]',
    'Faire une liste de courses et s\'y tenir',
    10,
    4,
    1
);

INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'Pour les petits montants, il est recommandé d utiliser:',
    '["Le crédit à la consommation", "Le paiement en espèces", "Les prélèvements automatiques", "Les crypto-monnaies"]',
    'Le paiement en espèces',
    5,
    4,
    1
);

INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'Analyser ses relevés bancaires permet de:',
    '["Perdre du temps", "Identifier les dépenses inutiles et réduire ses coûts", "Augmenter ses dépenses", "Ouvrir de nouveaux comptes"]',
    'Identifier les dépenses inutiles et réduire ses coûts',
    10,
    4,
    1
);

-- Quiz pour Cours 5: Cryptomonnaies
INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'Quelle a été la première cryptomonnaie créée?',
    '["Ethereum", "Bitcoin", "Litecoin", "Ripple"]',
    'Bitcoin',
    10,
    5,
    1
);

INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'Qu est-ce que la blockchain?',
    '["Un type de banque", "Une technologie de registre distribué", "Un moyen de paiement classique", "Un gouvernement décentralisé"]',
    'Une technologie de registre distribué',
    10,
    5,
    1
);

INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'Les investissements en cryptomonnaies sont:',
    '["Sans risque", "Très risqués et volatils", "Garantis par l\'État", "Toujours rentables"]',
    'Très risqués et volatils',
    10,
    5,
    1
);

-- Quiz pour Cours 6: Retraite
INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'Pourquoi commencer à épargner pour la retraite tôt est-il avantageux?',
    '["Les banks offrent de meilleurs taux aux jeunes", "Les intérêts composés font wonders sur le long terme", "Il n\'y a pas d\'avantage", "La retraite arrive vite"]',
    'Les intérêts composés font wonders sur le long terme',
    10,
    6,
    1
);

INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'Qu est-ce qu un PER?',
    '["Plan d Épargne Retraite", "Programme d Entretien Régulier", "Placement Externe Rentable", "Prêt Économique Retraite"]',
    'Plan d Épargne Retraite',
    10,
    6,
    1
);

INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'Faut-il uniquement compter sur la retraite publique?',
    '["Oui, c\'est suffisant", "Non, il faut diversifier ses sources de revenus", "Non, la retraite publique n\'existe pas", "Oui, l\'État garantit tout"]',
    'Non, il faut diversifier ses sources de revenus',
    10,
    6,
    1
);

-- Quiz pour Cours 7: Fonds d'Urgence
INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'Combien de mois de dépenses devrait contenir un fonds d urgence?',
    '["1 mois", "3 à 6 mois", "10 à 12 mois", "Cela dépend du pays"]',
    '3 à 6 mois',
    10,
    7,
    1
);

INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'Où est-il préférable de garder son fonds d urgence?',
    '["En bourse", "En cryptomonnaies", "Dans un compte épargne accessible", "Dans des biens immobiliers"]',
    'Dans un compte épargne accessible',
    10,
    7,
    1
);

INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'Quand utiliser un fonds d urgence?',
    '["Pour des vacances", "Pour des achats courants", "Pour des imprévus comme perte d\'emploi ou réparations urgentes", "Pour des investissements"]',
    'Pour des imprévus comme perte d\'emploi ou réparations urgentes',
    10,
    7,
    1
);

-- Quiz pour Cours 8: Psychologie de l'Argent
INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'Quelles émotions peuvent influencer négativement nos décisions financières?',
    '["La joie et le bonheur", "La peur et la cupidité", "La gratitude et la satisfaction", "L\'indifférence"]',
    'La peur et la cupidité',
    10,
    8,
    1
);

INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'Qu est-ce que le biais cognitif en finance?',
    '["Une erreur de calcul", "Une distorsion systématique dans notre jugement financier", "Un type d\'investissement", "Un compte bancaire"]',
    'Une distorsion systématique dans notre jugement financier',
    10,
    8,
    1
);

INSERT INTO quiz (question, choix_reponses, reponse_correcte, points_valeur, cours_id, user_id) 
VALUES (
    'La mentalité d abondance signifie:',
    '["Penser que l\'argent est la source du bonheur", "Croire qu\'il y a assez d\'opportunités pour tous", "Dépenser sans limite", "Accumuler richesse sans partage"]',
    'Croire qu\'il y a assez d\'opportunités pour tous',
    10,
    8,
    1
);
