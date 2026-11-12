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
            ->add('title', TextType::class, ['label' => 'Titel (Deutsch)', 'attr' => ['maxlength' => 120]])
            ->add('body', TextareaType::class, ['label' => 'Text (Deutsch)', 'attr' => ['rows' => 8]])
            ->add('titleEn', TextType::class, ['label' => 'Titel (Englisch)', 'attr' => ['maxlength' => 120, 'lang' => 'en']])
            ->add('bodyEn', TextareaType::class, ['label' => 'Text (Englisch)', 'attr' => ['rows' => 8, 'lang' => 'en']])
            // Rendered by hand next to its explanation (templates/game/_news_form.html.twig).
            ->add('welcome', CheckboxType::class, ['label' => false, 'required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => News::class, 'csrf_token_id' => 'news']);
    }
}
