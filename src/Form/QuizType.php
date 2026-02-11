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
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\CallbackTransformer;

class QuizType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('question', TextareaType::class, [
                'label' => 'Question',
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'Tapez votre question ici...'
                ]
            ])
            ->add('choixReponses', TextareaType::class, [
                'label' => 'Choix (JSON)',
                'attr' => [
                    'rows' => 5,
                    'placeholder' => '["A", "B", "C", "D"]'
                ]
            ])
            ->add('reponseCorrecte', TextareaType::class, [
                'label' => 'Réponse correcte',
                'attr' => [
                    'rows' => 1,
                    'placeholder' => 'Ex: A'
                ]
            ])
            ->add('pointsValeur', null, [
                'label' => 'Valeur en points',
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
