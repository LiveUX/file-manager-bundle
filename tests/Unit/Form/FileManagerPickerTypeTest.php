<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Form;

use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use LiveUX\FileManagerBundle\Theme\FileManagerThemes;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

final class FileManagerPickerTypeTest extends TypeTestCase
{
    public function testSinglePickerSubmitsString(): void
    {
        $form = $this->factory->create(FileManagerPickerType::class, null, [
            'profile' => 'products',
            'mode' => 'offcanvas',
            'position' => 'end',
            'theme' => 'flowbite',
            'variant' => 'card',
        ]);

        $form->submit('/uploads/urunler/termos.jpg');

        self::assertTrue($form->isSynchronized());
        self::assertSame('/uploads/urunler/termos.jpg', $form->getData());

        $view = $form->createView();
        self::assertSame('products', $view->vars['profile']);
        self::assertSame('offcanvas', $view->vars['mode']);
        self::assertSame('flowbite', $view->vars['theme']);
        self::assertSame('card', $view->vars['variant']);
        self::assertFalse($view->vars['multiple']);
    }

    public function testMultiplePickerTransformsJsonArray(): void
    {
        $form = $this->factory->create(FileManagerPickerType::class, ['/uploads/a.jpg'], [
            'multiple' => true,
            'max_files' => 5,
            'theme' => 'daisyui',
            'variant' => 'dropzone',
        ]);

        self::assertSame('["/uploads/a.jpg"]', $form->getViewData());

        $form->submit('["/uploads/a.jpg","/uploads/b.webp"]');

        self::assertTrue($form->isSynchronized());
        self::assertSame(['/uploads/a.jpg', '/uploads/b.webp'], $form->getData());

        $view = $form->createView();
        self::assertSame('daisyui', $view->vars['theme']);
        self::assertSame('dropzone', $view->vars['variant']);
    }

    public function testAllThemesAndVariantsAreAccepted(): void
    {
        foreach (FileManagerThemes::ALL as $theme) {
            foreach (FileManagerPickerType::VARIANTS as $variant) {
                $form = $this->factory->create(FileManagerPickerType::class, null, [
                    'theme' => $theme,
                    'variant' => $variant,
                ]);
                $view = $form->createView();
                self::assertSame($theme, $view->vars['theme']);
                self::assertSame($variant, $view->vars['variant']);
            }
        }
    }

    public function testCustomTemplateAndCustomBrandThemeAreAccepted(): void
    {
        $form = $this->factory->create(FileManagerPickerType::class, null, [
            'theme' => 'my-brand-theme',
            'template' => 'forms/my_custom_picker.html.twig',
        ]);
        $view = $form->createView();

        self::assertSame('my-brand-theme', $view->vars['theme']);
        self::assertSame('forms/my_custom_picker.html.twig', $view->vars['template']);
    }

    public function testInvalidVariantIsRejected(): void
    {
        $this->expectException(InvalidOptionsException::class);
        $this->factory->create(FileManagerPickerType::class, null, [
            'variant' => 'unknown_layout',
        ]);
    }

    public function testCollectionTypePrototypeAndMultiRowSubmission(): void
    {
        $form = $this->factory->createBuilder(FormType::class)
            ->add('files', CollectionType::class, [
                'entry_type' => FileManagerPickerType::class,
                'entry_options' => [
                    'profile' => 'default',
                    'variant' => 'input',
                    'multiple' => false,
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'prototype' => true,
            ])
            ->add('galleries', CollectionType::class, [
                'entry_type' => FileManagerPickerType::class,
                'entry_options' => [
                    'profile' => 'default',
                    'multiple' => true,
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'prototype' => true,
            ])
            ->getForm();

        $view = $form->createView();
        self::assertArrayHasKey('prototype', $view['files']->vars);
        self::assertArrayHasKey('prototype', $view['galleries']->vars);

        $form->submit([
            'files' => ['/uploads/a.jpg', '/uploads/b.pdf'],
            'galleries' => ['["/uploads/1.jpg","/uploads/2.jpg"]', '["/uploads/3.png"]'],
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame(['/uploads/a.jpg', '/uploads/b.pdf'], $form->getData()['files']);
        self::assertSame([['/uploads/1.jpg', '/uploads/2.jpg'], ['/uploads/3.png']], $form->getData()['galleries']);
    }

    public function testLiveCollectionEmptyArrayPreSubmitNormalization(): void
    {
        // Multiple picker accepts `[]` directly (LiveCollectionTrait::addCollectionItem() initializes new items as `[]`).
        $multi = $this->factory->create(FileManagerPickerType::class, [], ['multiple' => true]);
        $multi->submit([]);
        self::assertTrue($multi->isSynchronized());
        self::assertSame([], $multi->getData());

        if (class_exists(LiveCollectionType::class)) {
            $builder = $this->factory->createBuilder(FormType::class)
                ->add('quickFiles', LiveCollectionType::class, [
                    'entry_type' => FileManagerPickerType::class,
                    'entry_options' => ['multiple' => false],
                    'allow_add' => true,
                    'allow_delete' => true,
                ])
                ->add('galleries', LiveCollectionType::class, [
                    'entry_type' => FileManagerPickerType::class,
                    'entry_options' => ['multiple' => true],
                    'allow_add' => true,
                    'allow_delete' => true,
                ]);

            $builder->get('quickFiles')->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
                $rows = $event->getData();
                if (is_array($rows)) {
                    $event->setData(array_map(static fn (mixed $v): mixed => $v === [] ? '' : $v, $rows));
                }
            });

            $liveForm = $builder->getForm();
            $liveForm->submit([
                'quickFiles' => [0 => '/uploads/hero.webp', 1 => []],
                'galleries' => [0 => '["/uploads/a.jpg"]', 1 => []],
            ]);
            self::assertTrue($liveForm->isSynchronized());
            self::assertSame([0 => '/uploads/hero.webp', 1 => null], $liveForm->getData()['quickFiles']);
            self::assertSame([0 => ['/uploads/a.jpg'], 1 => []], $liveForm->getData()['galleries']);
        }
    }
}
