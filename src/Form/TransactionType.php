<?php

namespace App\Form;

use App\Entity\Transaction;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TransactionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'choices' => [
                    'Expense' => 'EXPENSE',
                    'Saving' => 'SAVING',
                    'Investment' => 'INVESTMENT',
                ],
            ])
            ->add('montant', NumberType::class)
            ->add('description', TextareaType::class, [
                'required' => false,
            ])
            ->add('moduleSource', null, [
                'required' => false,
            ])
            // ❌ plus de choix d'utilisateur dans le formulaire :
            // l'utilisateur sera toujours le user connecté dans le contrôleur
            ->add('date', DateType::class, [
                'widget' => 'single_text',
                'data' => new \DateTime(),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Transaction::class,
        ]);
    }
}
