<?php

namespace App\Form;

use App\Entity\CasRelles;
use App\Entity\SavingAccount;
use App\Entity\Imprevus;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;

class CasRellesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titre', TextType::class, [
                'label' => 'Titre',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Entrez le titre',
                    'maxlength' => 150,
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Le titre est obligatoire.',
                    ]),
                    new Assert\Length([
                        'max' => 150,
                        'maxMessage' => 'Le titre ne peut pas dépasser {{ limit }} caractères.',
                    ]),
                ],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Type',
                'attr' => ['class' => 'form-select'],
                'choices' => [
                    'Positif (+)' => 'POSITIF',
                    'Négatif (-)' => 'NEGATIF',
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Le type est obligatoire.',
                    ]),
                    new Assert\Choice([
                        'choices' => ['POSITIF', 'NEGATIF'],
                        'message' => 'Veuillez sélectionner un type valide.',
                    ]),
                ],
            ])
            ->add('montant', NumberType::class, [
                'label' => 'Montant (DT)',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Entrez le montant',
                    'min' => 0,
                    'step' => '0.01',
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Le montant est obligatoire.',
                    ]),
                    new Assert\Positive([
                        'message' => 'Le montant doit être supérieur à zéro.',
                    ]),
                    new Assert\LessThanOrEqual([
                        'value' => 1000000,
                        'message' => 'Le montant ne peut pas dépasser {{ compared_value }} DT.',
                    ]),
                ],
            ])
            ->add('solution', ChoiceType::class, [
                'label' => 'Solution',
                'attr' => ['class' => 'form-select'],
                'choices' => [
                    'Fonds de Sécurité' => 'FONDS_SECURITE',
                    'Épargne' => 'EPARGNE',
                    'Famille' => 'FAMILLE',
                    'Compte' => 'COMPTE',
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'La solution est obligatoire.',
                    ]),
                ],
            ])
            ->add('epargne', EntityType::class, [
                'label' => 'Compte d\'épargne',
                'class' => SavingAccount::class,
                'choice_label' => function (SavingAccount $epargne) {
                    return '#' . $epargne->getId() . ' - Solde: ' . ($epargne->getSold() ?? 0) . ' DT';
                },
                'attr' => ['class' => 'form-select'],
                'required' => false,
                'placeholder' => 'Sélectionnez un compte (optionnel)',
            ])
            ->add('imprevus', EntityType::class, [
                'label' => 'Imprévu associé',
                'class' => Imprevus::class,
                'choice_label' => 'titre',
                'attr' => ['class' => 'form-select'],
                'required' => false,
                'placeholder' => 'Sélectionnez un imprévu (optionnel)',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Entrez une description',
                    'rows' => 4,
                ],
                'required' => false,
            ])
            ->add('dateEffet', DateType::class, [
                'label' => 'Date d\'effet',
                'attr' => ['class' => 'form-control'],
                'widget' => 'single_text',
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'La date d\'effet est obligatoire.',
                    ]),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CasRelles::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'casrelles_form',
        ]);
    }
}
