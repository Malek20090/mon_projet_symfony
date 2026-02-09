<?php

namespace App\Form;

use App\Entity\Expense;
use App\Entity\Revenue;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ExpenseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('amount', NumberType::class, [
                'label' => 'Montant',
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => 0],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'Catégorie',
                'choices' => [
                    'Alimentation' => 'Food',
                    'Transport' => 'Transport',
                    'Logement' => 'Housing',
                    'Santé' => 'Health',
                    'Loisirs' => 'Leisure',
                    'Shopping' => 'Shopping',
                    'Autre' => 'Other',
                ],
            ])
            ->add('expenseDate', DateType::class, [
                'label' => 'Date de la dépense',
                'widget' => 'single_text',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
            ])
            ->add('revenue', EntityType::class, [
                'class' => Revenue::class,
                'choice_label' => fn(Revenue $r) => sprintf('#%d - %s € (%s)', $r->getId(), number_format($r->getAmount(), 2), $r->getReceivedAt()?->format('d/m/Y') ?? ''),
                'label' => 'Revenu associé',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Expense::class,
        ]);
    }
}
