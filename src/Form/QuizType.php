<?php

namespace App\Form;

use App\Entity\Quiz;
use App\Entity\Cours;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class QuizType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('question', TextareaType::class, [
                'label' => 'Question',
                'constraints' => [
                    new Assert\NotBlank(message: 'La question est obligatoire.'),
                    new Assert\Length(min: 10, minMessage: 'La question doit contenir au moins 10 caractères.')
                ],
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'Tapez votre question ici...'
                ]
            ])
            ->add('choixReponses', TextareaType::class, [
                'label' => 'Choix (JSON)',
                'constraints' => [
                    new Assert\NotBlank(message: 'Les choix sont obligatoires.'),
                    new Callback(function ($value, ExecutionContextInterface $context) {
                        $decoded = json_decode((string) $value, true);
                        if ($decoded === null || !is_array($decoded) || count($decoded) < 2) {
                            $context->buildViolation('Le JSON doit être un tableau avec au moins 2 choix.')
                                ->addViolation();
                            return;
                        }
                        foreach ($decoded as $opt) {
                            if (!is_string($opt) || trim($opt) === '') {
                                $context->buildViolation('Chaque choix doit être une chaîne non vide.')
                                    ->addViolation();
                                return;
                            }
                        }
                    })
                ],
                'attr' => [
                    'rows' => 5,
                    'placeholder' => '["A", "B", "C", "D"]'
                ]
            ])
            ->add('reponseCorrecte', TextareaType::class, [
                'label' => 'Réponse correcte',
                'constraints' => [
                    new Assert\NotBlank(message: 'La réponse correcte est obligatoire.'),
                    new Assert\Length(max: 100, maxMessage: 'La réponse ne doit pas dépasser 100 caractères.')
                ],
                'attr' => [
                    'rows' => 1,
                    'placeholder' => 'Ex: A'
                ]
            ])
            ->add('pointsValeur', IntegerType::class, [
                'label' => 'Valeur en points',
                'constraints' => [
                    new Assert\NotBlank(message: 'Les points sont obligatoires.'),
                    new Assert\Range(min: 1, max: 1000, notInRangeMessage: 'Les points doivent être entre 1 et 1000.')
                ],
                'attr' => [
                    'min' => 1,
                    'max' => 1000
                ]
            ])
            ->add('cours', EntityType::class, [
                'class' => Cours::class,
                'choice_label' => 'titre', // plus lisible qu'id
                'label' => 'Cours associé',
            ])
            ->add('user', EntityType::class, [
                'class' => User::class,
                'choice_label' => function(User $user) {
                    if ($user->getNom() && $user->getEmail()) {
                        return $user->getNom() . ' (' . $user->getEmail() . ')';
                    } elseif ($user->getEmail()) {
                        return $user->getEmail();
                    } elseif ($user->getNom()) {
                        return $user->getNom();
                    } else {
                        return 'User #' . $user->getId();
                    }
                },
                'label' => 'Créé par',
                'placeholder' => 'Sélectionner un utilisateur',
                'required' => false,
                'query_builder' => function(EntityRepository $er) {
                    return $er->createQueryBuilder('u')
                        ->orderBy('u.nom', 'ASC')
                        ->addOrderBy('u.email', 'ASC');
                },
                'attr' => [
                    'class' => 'form-select'
                ]
            ])
        ;

        // Transformer array <-> JSON string pour choixReponses
        $builder->get('choixReponses')
            ->addModelTransformer(new CallbackTransformer(
                fn($array) => $array ? json_encode($array, JSON_PRETTY_PRINT) : '',
                fn($json) => $json ? json_decode($json, true) : []
            ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Quiz::class,
        ]);
    }
}
