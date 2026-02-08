<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType; // ✅ IMPORTANT
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Validator\Constraints\File;


class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('image', FileType::class, [
    'label' => 'Photo utilisateur',
    'mapped' => false,
    'required' => false,
    'constraints' => [
        new File([
            'maxSize' => '2M',
            'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
            'mimeTypesMessage' => 'Veuillez uploader une image valide',
        ])
    ],
])
            ->add('nom', TextType::class)
            ->add('email', TextType::class)
            ->add('password', PasswordType::class)
            ->add('roles', ChoiceType::class, [
                'label' => 'Rôle',
                'choices' => [
                    'Admin' => 'ROLE_ADMIN',
                    'Salary' => 'ROLE_SALARY',
                    'Étudiant' => 'ROLE_ETUDIANT',
                ],
                'expanded' => false,
                'multiple' => true, // ⚠️ roles est un tableau
            ])
            ->add('soldeTotal', NumberType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
