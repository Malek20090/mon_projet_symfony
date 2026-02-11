<?php

namespace App\Form;

use App\Entity\Investissement;
use App\Entity\User;
use App\Entity\Crypto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AdminInvestissementCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('userId', EntityType::class, [
                'class' => User::class,
                'choice_label' => 'email',
                'label' => 'Utilisateur'
            ])
            ->add('crypto', EntityType::class, [
                'class' => Crypto::class,
                'choice_label' => 'name',
            ])
            ->add('amountInvested', NumberType::class, [
                'label' => 'Montant investi'
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Investissement::class,
        ]);
    }
}
