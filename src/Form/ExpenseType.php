<?php

namespace App\Form;

use App\Entity\Expense;
use App\Entity\Revenue;
use App\Entity\User;
use App\Repository\RevenueRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ExpenseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('amount', NumberType::class, [
                'label' => 'Montant',
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => 0],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Le montant est obligatoire.']),
                    new Assert\GreaterThanOrEqual([
                        'value' => 0,
                        'message' => 'Le montant doit etre superieur ou egal a 0.',
                    ]),
                ],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'Categorie',
                'choices' => [
                    'Alimentation' => 'Food',
                    'Transport' => 'Transport',
                    'Logement' => 'Housing',
                    'Sante' => 'Health',
                    'Loisirs' => 'Leisure',
                    'Shopping' => 'Shopping',
                    'Autre' => 'Other',
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'La categorie est obligatoire.']),
                ],
            ])
            ->add('expenseDate', DateType::class, [
                'label' => 'Date de la depense',
                'widget' => 'single_text',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'La date de depense est obligatoire.']),
                    new Assert\LessThanOrEqual([
                        'value' => 'today',
                        'message' => 'La date de depense ne peut pas etre dans le futur.',
                    ]),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'constraints' => [
                    new Assert\Length([
                        'max' => 500,
                        'maxMessage' => 'La description ne doit pas depasser {{ limit }} caracteres.',
                    ]),
                ],
            ])
            ->add('revenue', EntityType::class, [
                'class' => Revenue::class,
                'query_builder' => function (RevenueRepository $revenueRepository) use ($options) {
                    $qb = $revenueRepository->createQueryBuilder('r')
                        ->orderBy('r.receivedAt', 'DESC')
                        ->addOrderBy('r.id', 'DESC');

                    if ($options['user'] instanceof User) {
                        $qb->andWhere('r.user = :user')
                            ->setParameter('user', $options['user']);
                    }

                    return $qb;
                },
                'choice_label' => fn (Revenue $r) => sprintf(
                    '#%d - %s TND (%s)',
                    $r->getId(),
                    number_format($r->getAmount(), 2),
                    $r->getReceivedAt()?->format('d/m/Y') ?? ''
                ),
                'label' => 'Revenu associe',
                'placeholder' => 'Selectionner un revenu',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Le revenu associe est obligatoire.']),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Expense::class,
            'user' => null,
        ]);

        $resolver->setAllowedTypes('user', ['null', User::class]);
    }
}
