<?php

namespace App\Form;

use App\Entity\Cours;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CoursType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titre')
            ->add('contenuTexte')
            ->add('typeMedia')
            ->add('urlMedia')
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Cours::class,
        ]);
    }
}
