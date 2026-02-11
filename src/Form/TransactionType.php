<?php

namespace App\Form;

use App\Entity\Transaction;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TransactionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('user', EntityType::class, [
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return $user->getNom() . ' (' . $user->getEmail() . ')';
                },
                'placeholder' => 'Choisir un utilisateur',
            ])

            ->add('type', ChoiceType::class, [
                'choices' => [
                    'Expense' => 'EXPENSE',
                    'Saving' => 'SAVING',
                    'Investment' => 'INVESTMENT',
                ],
            ])

            ->add('montant', NumberType::class)

            ->add('date', DateType::class, [
                'widget' => 'single_text',
            ])

            ->add('description', TextareaType::class, [
                'required' => false,
            ])

            ->add('moduleSource', null, [
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Transaction::class,
        ]);
    }
}
