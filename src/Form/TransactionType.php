<?php

namespace App\Form;

use App\Entity\Transaction;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

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
                'label' => 'User',
                'placeholder' => 'Select a user',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Please select a user.']),
                ],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Type',
                'choices' => [
                    'Expense' => 'EXPENSE',
                    'Saving' => 'SAVING',
                    'Investment' => 'INVESTMENT',
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Please select a transaction type.']),
                ],
            ])
            ->add('montant', NumberType::class, [
                'label' => 'Amount',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Amount is required.']),
                    new Assert\GreaterThan(['value' => 0, 'message' => 'Amount must be greater than 0.']),
                    new Assert\LessThanOrEqual(['value' => 1000000, 'message' => 'Amount cannot exceed 1,000,000.']),
                ],
            ])
            ->add('date', DateType::class, [
                'label' => 'Date',
                'widget' => 'single_text',
                'data' => new \DateTime(),
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Date is required.']),
                    new Assert\LessThanOrEqual(['value' => 'today', 'message' => 'Date cannot be in the future.']),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'constraints' => [
                    new Assert\Length([
                        'max' => 500,
                        'maxMessage' => 'Description cannot exceed {{ limit }} characters.',
                    ]),
                ],
            ])
            ->add('moduleSource', TextType::class, [
                'label' => 'Source',
                'required' => false,
                'constraints' => [
                    new Assert\Length([
                        'max' => 50,
                        'maxMessage' => 'Source cannot exceed {{ limit }} characters.',
                    ]),
                    new Assert\Regex([
                        'pattern' => '/^[A-Za-z0-9 _.-]*$/',
                        'message' => 'Source can contain only letters, numbers, space, dot, underscore and dash.',
                    ]),
                ],
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
