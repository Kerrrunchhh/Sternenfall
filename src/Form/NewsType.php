<?php

namespace App\Form;

use App\Entity\News;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Admins write and edit news. */
class NewsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Titel', 'attr' => ['maxlength' => 120]])
            ->add('body', TextareaType::class, ['label' => 'Text', 'attr' => ['rows' => 8]])
            ->add('welcome', CheckboxType::class, [
                'label' => 'Willkommensnachricht (sieht jeder neue Spieler)',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => News::class, 'csrf_token_id' => 'news']);
    }
}
