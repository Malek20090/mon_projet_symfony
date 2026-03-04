<?php

namespace App\DataFixtures;

use App\Entity\Cours;
use App\Entity\Quiz;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class CoursQuizFinanceFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $coursRepo = $manager->getRepository(Cours::class);
        $quizRepo = $manager->getRepository(Quiz::class);

        $catalog = [
            [
                'titre' => 'Budget personnel 50/30/20',
                'contenu' => 'Ce cours explique la methode 50/30/20: 50% besoins, 30% envies, 20% epargne. Vous apprendrez a construire un budget mensuel, fixer des plafonds par categorie et suivre les ecarts.',
                'type' => 'ARTICLE',
                'url' => 'https://www.investopedia.com/ask/answers/022916/what-502030-budget-rule.asp',
                'quizzes' => [
                    [
                        'question' => 'Dans la methode 50/30/20, quelle part du revenu est reservee a l epargne ?',
                        'choix' => ['10%', '20%', '30%', '40%'],
                        'correcte' => '20%',
                        'points' => 10,
                    ],
                    [
                        'question' => 'Les "besoins" dans 50/30/20 correspondent a:',
                        'choix' => ['Loyer, alimentation, transport', 'Restaurants de luxe', 'Voyages annuels', 'Achats impulsifs'],
                        'correcte' => 'Loyer, alimentation, transport',
                        'points' => 10,
                    ],
                    [
                        'question' => 'Quel est le principal avantage d un budget mensuel ?',
                        'choix' => ['Depenser plus vite', 'Reduire la visibilite', 'Mieux controler les priorites', 'Eviter toute epargne'],
                        'correcte' => 'Mieux controler les priorites',
                        'points' => 10,
                    ],
                ],
            ],
            [
                'titre' => 'Fonds d urgence et securite financiere',
                'contenu' => 'Le fonds d urgence couvre les imprévus: sante, perte d emploi, reparation. Ce module detaille le montant cible (3 a 6 mois de depenses) et la strategie de constitution progressive.',
                'type' => 'VIDEO',
                'url' => 'https://www.youtube.com/watch?v=Q5jlY8_WmEE',
                'quizzes' => [
                    [
                        'question' => 'Quel est un objectif classique pour un fonds d urgence ?',
                        'choix' => ['1 semaine de depenses', '3 a 6 mois de depenses', '1 jour de depenses', '12 ans de depenses'],
                        'correcte' => '3 a 6 mois de depenses',
                        'points' => 10,
                    ],
                    [
                        'question' => 'Ou placer en priorite un fonds d urgence ?',
                        'choix' => ['Actifs tres volatils', 'Compte liquide et accessible', 'Crypto speculative uniquement', 'Objets de collection'],
                        'correcte' => 'Compte liquide et accessible',
                        'points' => 10,
                    ],
                    [
                        'question' => 'Pourquoi separer le fonds d urgence du compte courant ?',
                        'choix' => ['Pour depenser plus', 'Pour eviter les retraits impulsifs', 'Pour payer plus de frais', 'Aucune raison'],
                        'correcte' => 'Pour eviter les retraits impulsifs',
                        'points' => 10,
                    ],
                ],
            ],
            [
                'titre' => 'Introduction a l investissement long terme',
                'contenu' => 'Ce cours presente le couple rendement-risque, la diversification et l horizon de placement. Vous verrez pourquoi la discipline et le temps sont essentiels pour investir durablement.',
                'type' => 'PDF',
                'url' => 'https://www.amf-france.org/fr/espace-epargnants',
                'quizzes' => [
                    [
                        'question' => 'La diversification sert principalement a:',
                        'choix' => ['Augmenter le risque specifique', 'Reduire le risque global', 'Garantir un gain', 'Supprimer les pertes'],
                        'correcte' => 'Reduire le risque global',
                        'points' => 10,
                    ],
                    [
                        'question' => 'Un horizon long terme est important car:',
                        'choix' => ['Il augmente les frais', 'Il laisse le temps de lisser la volatilite', 'Il supprime les impots', 'Il force la speculaton'],
                        'correcte' => 'Il laisse le temps de lisser la volatilite',
                        'points' => 10,
                    ],
                    [
                        'question' => 'Rendement potentiel plus eleve signifie generalement:',
                        'choix' => ['Risque plus faible', 'Risque nul', 'Risque plus eleve', 'Aucune relation'],
                        'correcte' => 'Risque plus eleve',
                        'points' => 10,
                    ],
                ],
            ],
            [
                'titre' => 'Actions, obligations, ETF: comprendre la difference',
                'contenu' => 'Vous apprendrez les caracteristiques des actions, obligations et ETF. Ce cours aide a choisir une allocation adaptee au profil de risque et aux objectifs.',
                'type' => 'ARTICLE',
                'url' => 'https://www.investor.gov/introduction-investing',
                'quizzes' => [
                    [
                        'question' => 'Un ETF est principalement:',
                        'choix' => ['Un compte bancaire', 'Un fonds cote en bourse', 'Un credit', 'Une assurance auto'],
                        'correcte' => 'Un fonds cote en bourse',
                        'points' => 10,
                    ],
                    [
                        'question' => 'Les obligations sont generalement associees a:',
                        'choix' => ['Un niveau de risque souvent plus modere que les actions', 'Un risque toujours maximal', 'Aucun rendement possible', 'Aucune date d echeance'],
                        'correcte' => 'Un niveau de risque souvent plus modere que les actions',
                        'points' => 10,
                    ],
                    [
                        'question' => 'Pourquoi beaucoup d investisseurs utilisent des ETF diversifies ?',
                        'choix' => ['Pour concentrer le risque sur une seule action', 'Pour diversifier facilement', 'Pour eviter toute fluctuation', 'Pour ignorer les frais'],
                        'correcte' => 'Pour diversifier facilement',
                        'points' => 10,
                    ],
                ],
            ],
            [
                'titre' => 'Gestion du risque en investissement',
                'contenu' => 'Ce module couvre le profil de risque, le reequilibrage de portefeuille, et les erreurs comportementales. Objectif: proteger le capital tout en visant une croissance stable.',
                'type' => 'VIDEO',
                'url' => 'https://www.youtube.com/watch?v=5QOTBreQaIk',
                'quizzes' => [
                    [
                        'question' => 'Le reequilibrage de portefeuille permet de:',
                        'choix' => ['Ignorer le risque', 'Revenir a l allocation cible', 'Supprimer la volatilite', 'Multiplier les frais sans raison'],
                        'correcte' => 'Revenir a l allocation cible',
                        'points' => 10,
                    ],
                    [
                        'question' => 'Le risque de concentration apparait quand:',
                        'choix' => ['Le portefeuille est trop diversifie', 'Une part trop importante est sur un seul actif', 'On detient des obligations', 'On suit un budget'],
                        'correcte' => 'Une part trop importante est sur un seul actif',
                        'points' => 10,
                    ],
                    [
                        'question' => 'Quel comportement augmente souvent les pertes ?',
                        'choix' => ['Plan d investissement discipline', 'Paniques et decisions emotionnelles', 'Diversification progressive', 'Suivi periodique'],
                        'correcte' => 'Paniques et decisions emotionnelles',
                        'points' => 10,
                    ],
                ],
            ],
            [
                'titre' => 'Cryptoactifs: bases et precautions',
                'contenu' => 'Ce cours introduit Bitcoin, Ethereum, la volatilite et les risques specifiques crypto. Il insiste sur la securite des wallets, la gestion de taille de position et la regulation.',
                'type' => 'ARTICLE',
                'url' => 'https://www.coinbase.com/learn/crypto-basics',
                'quizzes' => [
                    [
                        'question' => 'La volatilite des cryptoactifs est generalement:',
                        'choix' => ['Faible et stable', 'Nulle', 'Elevee', 'Toujours previsible'],
                        'correcte' => 'Elevee',
                        'points' => 10,
                    ],
                    [
                        'question' => 'Une bonne pratique de securite est de:',
                        'choix' => ['Partager sa seed phrase', 'Activer l authentification forte', 'Mettre tous les fonds sur une seule plateforme non securisee', 'Utiliser le meme mot de passe partout'],
                        'correcte' => 'Activer l authentification forte',
                        'points' => 10,
                    ],
                    [
                        'question' => 'Pourquoi limiter la part crypto dans un portefeuille debutant ?',
                        'choix' => ['A cause du risque et de la volatilite', 'Parce que c est toujours sans valeur', 'Parce que c est interdit partout', 'Pour eviter la diversification'],
                        'correcte' => 'A cause du risque et de la volatilite',
                        'points' => 10,
                    ],
                ],
            ],
            [
                'titre' => 'Bitcoin et Ethereum: usages et differences',
                'contenu' => 'Comparaison entre Bitcoin (reserve de valeur numerique) et Ethereum (ecosysteme de smart contracts). Ce module montre les cas d usage et limites de chaque reseau.',
                'type' => 'VIDEO',
                'url' => 'https://www.youtube.com/watch?v=ZE2HxTmxfrI',
                'quizzes' => [
                    [
                        'question' => 'Ethereum est surtout connu pour:',
                        'choix' => ['Les smart contracts', 'Les obligations d etat', 'Les livrets bancaires', 'Le forex classique'],
                        'correcte' => 'Les smart contracts',
                        'points' => 10,
                    ],
                    [
                        'question' => 'Bitcoin est souvent presente comme:',
                        'choix' => ['Un stablecoin', 'Une reserve de valeur numerique', 'Une action technologique', 'Un compte epargne garanti'],
                        'correcte' => 'Une reserve de valeur numerique',
                        'points' => 10,
                    ],
                    [
                        'question' => 'Lequel est un risque commun a BTC et ETH ?',
                        'choix' => ['Volatilite de prix', 'Rendement garanti', 'Absence totale de risque', 'Aucune variation de marche'],
                        'correcte' => 'Volatilite de prix',
                        'points' => 10,
                    ],
                ],
            ],
            [
                'titre' => 'Cycle de marche et psychologie investisseur',
                'contenu' => 'Vous allez comprendre les phases de marche (accumulation, tendance, euphorie, correction) et comment eviter les erreurs de timing basees sur l emotion.',
                'type' => 'PDF',
                'url' => 'https://www.fidelity.com/learning-center/investment-products/stocks/market-cycles',
                'quizzes' => [
                    [
                        'question' => 'La FOMO en investissement signifie:',
                        'choix' => ['Fear of Missing Out', 'Future of Market Options', 'Fast Order Management Only', 'Financial Objective Mapping'],
                        'correcte' => 'Fear of Missing Out',
                        'points' => 10,
                    ],
                    [
                        'question' => 'Une strategie utile contre les decisions emotionnelles est:',
                        'choix' => ['Avoir un plan d allocation et le suivre', 'Acheter uniquement apres une rumeur', 'Changer de strategie chaque jour', 'Ignorer totalement le risque'],
                        'correcte' => 'Avoir un plan d allocation et le suivre',
                        'points' => 10,
                    ],
                    [
                        'question' => 'En periode de correction, un investisseur discipline fait plutot:',
                        'choix' => ['Panique systematique', 'Revue des fondamentaux et du plan', 'Vente sans analyse', 'Arret complet du suivi budget'],
                        'correcte' => 'Revue des fondamentaux et du plan',
                        'points' => 10,
                    ],
                ],
            ],
        ];

        foreach ($catalog as $item) {
            $cours = $coursRepo->findOneBy(['titre' => $item['titre']]);
            if (!$cours instanceof Cours) {
                $cours = new Cours();
                $cours->setTitre($item['titre']);
                $cours->setContenuTexte($item['contenu']);
                $cours->setTypeMedia($item['type']);
                $cours->setUrlMedia($item['url']);
                $manager->persist($cours);
            }

            foreach ($item['quizzes'] as $q) {
                $existingQuiz = $quizRepo->findOneBy(['question' => $q['question']]);
                if ($existingQuiz instanceof Quiz) {
                    continue;
                }

                $quiz = new Quiz();
                $quiz->setQuestion($q['question']);
                $quiz->setChoixReponses($q['choix']);
                $quiz->setReponseCorrecte($q['correcte']);
                $quiz->setPointsValeur((int) $q['points']);
                $quiz->setCours($cours);

                $manager->persist($quiz);
            }
        }

        $manager->flush();
    }
}

