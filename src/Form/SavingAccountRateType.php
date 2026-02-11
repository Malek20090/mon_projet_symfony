<?php

namespace App\Form;

use App\Entity\SavingAccount;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SavingAccountRateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('tauxInteret', NumberType::class, [
            'label' => 'Interest rate (%)',
            'required' => true,
            'scale' => 2,
            'attr' => [
                'min' => 0,
                'step' => 0.01,
                'class' => 'form-control',
                'placeholder' => 'Ex: 2.5'
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SavingAccount::class,
        ]);
    }
}
