<?php

namespace App\Form;

use App\Entity\Investissement;
use App\Entity\Objectif;
use App\Repository\InvestissementRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class ObjectifType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom de l’objectif',
            ])
            ->add('targetMultiplier', NumberType::class, [
                'label' => 'Multiplicateur cible',
                'scale' => 4,
                'html5' => true,
                'attr' => [
                    'step' => '0.0001',
                ],
            ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($options) {

            $objectif = $event->getData();
            $form = $event->getForm();

            if (!$objectif) {
                return;
            }

            $form->add('investissements', EntityType::class, [
                'class' => Investissement::class,
                'choice_label' => function (Investissement $investissement) {
                    return sprintf(
                        '%s - %.2f USD',
                        $investissement->getCrypto()->getName(),
                        $investissement->getAmountInvested()
                    );
                },
                'multiple' => true,
                'expanded' => true,
                'label' => 'Investissements disponibles',
                'query_builder' => function (InvestissementRepository $repo) use ($objectif, $options) {

                    $qb = $repo->createQueryBuilder('i');

                    // 👑 ADMIN → voit tous les investissements libres
                    if ($options['is_admin']) {
                        $qb->where('i.objectif IS NULL');
                    } 
                    // 👤 USER → voit seulement SES investissements libres
                    else {
                        $qb->where('i.objectif IS NULL')
                           ->andWhere('i.user_id = :user')
                           ->setParameter('user', $options['current_user']);
                    }

                    // 🔄 En édition → garder ceux déjà liés à cet objectif
                    if ($objectif->getId() !== null) {
                        $qb->orWhere('i.objectif = :objectif')
                           ->setParameter('objectif', $objectif);
                    }

                    return $qb;
                },
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Objectif::class,
            'current_user' => null,
            'is_admin' => false,
        ]);
    }
}
        