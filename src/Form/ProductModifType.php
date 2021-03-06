<?php

namespace App\Form;

use App\Entity\Gammes;
use App\Entity\Product;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProductModifType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name',TextType::class)
            ->add('description',TextareaType::class)
            ->add('price',MoneyType::class)
            ->add('stock',IntegerType::class)
            ->add('brand',TextType::class)
            ->add('gammes',EntityType::class,[
                'class' => Gammes::class,
                'choice_label' => 'name'
            ])
            ->add('reference',TextType::class)
            ->add('save',SubmitType::class,[
                'label' => 'ajouter !'
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Product::class,
        ]);
    }
}
