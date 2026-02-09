<?php

namespace App\Form;

use App\Entity\FinancialGoal;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FinancialGoalType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Goal name',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ex: Buy a car'],
            ])
            ->add('montantCible', NumberType::class, [
                'label' => 'Target (TND)',
                'scale' => 2,
                'attr' => ['class' => 'form-control', 'min' => 0, 'step' => 0.01],
            ])
            // montantActuel is not editable in create/edit (only via contribute)
            ->add('dateLimite', DateType::class, [
                'label' => 'Deadline',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('priorite', IntegerType::class, [
                'label' => 'Priority (1-5)',
                'required' => true,
                'attr' => ['class' => 'form-control', 'min' => 1, 'max' => 5],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => FinancialGoal::class,
        ]);
    }
}
