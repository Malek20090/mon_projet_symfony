<?php

namespace App\Form;

use App\Entity\Cours;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class CoursType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titre', TextType::class, [
                'constraints' => [
                    new Assert\NotBlank(message: 'Le titre est obligatoire.'),
                    new Assert\Length(max: 150, maxMessage: 'Le titre ne doit pas dépasser 150 caractères.')
                ],
                'attr' => [
                    'class' => 'form-control',
                    'maxlength' => 150
                ]
            ])
            ->add('contenuTexte', TextareaType::class, [
                'constraints' => [
                    new Assert\NotBlank(message: 'Le contenu est obligatoire.'),
                    new Assert\Length(min: 10, minMessage: 'Le contenu doit contenir au moins 10 caractères.')
                ],
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 8
                ]
            ])
            ->add('typeMedia', ChoiceType::class, [
                'choices' => [
                    'Video' => 'video',
                    'Image' => 'image',
                    'PDF' => 'pdf',
                ],
                'placeholder' => 'Sélectionner un type',
                'constraints' => [
                    new Assert\NotBlank(message: 'Le type media est obligatoire.'),
                    new Assert\Choice(choices: ['video','image','pdf'], message: 'Type media invalide.')
                ],
                'attr' => [
                    'class' => 'form-select'
                ]
            ])
            ->add('urlMedia', UrlType::class, [
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(message: 'Le lien media est obligatoire.'),
                    new Assert\Url(message: 'L’URL n’est pas valide.')
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'https://...'
                ]
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Cours::class,
        ]);
    }
}
