<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Bridge\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\FieldTrait;
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Optional EasyAdmin 4/5 Field wrapper around FileManagerPickerType.
 *
 * Requires `easycorp/easyadmin-bundle` in the host application. Never eagerly
 * registered as a Symfony service so projects without EasyAdmin incur zero overhead.
 *
 * Usage inside an EasyAdmin CrudController:
 *   yield FileManagerField::new('coverImage', 'Cover Image')
 *       ->setProfile('blog_images')
 *       ->setVariant('dropzone');
 *
 *   yield FileManagerField::new('gallery', 'Product Gallery')
 *       ->setMultiple(true)
 *       ->setMaxFiles(10)
 *       ->setVariant('card');
 */
final class FileManagerField implements FieldInterface
{
    use FieldTrait;

    /**
     * @param TranslatableInterface|string|bool|null $label
     */
    public static function new(string $propertyName, TranslatableInterface|string|bool|null $label = null): self
    {
        return (new self())
            ->setProperty($propertyName)
            ->setLabel($label === true ? null : $label)
            ->setTemplatePath('@LiveUXFileManager/easyadmin/field.html.twig')
            ->setFormType(FileManagerPickerType::class)
            ->addFormTheme('@LiveUXFileManager/form/picker.html.twig')
            ->setCustomOption('profile', 'default')
            ->setCustomOption('volume', null)
            ->setCustomOption('preview', true)
            ->setDefaultColumns('col-md-8 col-xxl-6');
    }

    public function setProfile(string $profile): self
    {
        $this->setFormTypeOption('profile', $profile);
        $this->setCustomOption('profile', $profile);

        return $this;
    }

    public function setVolume(?string $volume): self
    {
        $this->setFormTypeOption('volume', $volume);
        $this->setCustomOption('volume', $volume);

        return $this;
    }

    public function setLocale(?string $locale): self
    {
        $this->setFormTypeOption('locale', $locale);

        return $this;
    }

    public function setMultiple(bool $multiple = true): self
    {
        $this->setFormTypeOption('multiple', $multiple);

        return $this;
    }

    public function setMaxFiles(?int $maxFiles): self
    {
        $this->setFormTypeOption('max_files', $maxFiles);

        return $this;
    }

    public function setMode(string $mode): self
    {
        $this->setFormTypeOption('mode', $mode);

        return $this;
    }

    public function setPosition(string $position): self
    {
        $this->setFormTypeOption('position', $position);

        return $this;
    }

    public function setMobileMode(?string $mobileMode): self
    {
        $this->setFormTypeOption('mobile_mode', $mobileMode);

        return $this;
    }

    public function setReturnType(string $returnType): self
    {
        $this->setFormTypeOption('return_type', $returnType);

        return $this;
    }

    public function setPreview(bool $preview = true): self
    {
        $this->setFormTypeOption('preview', $preview);
        $this->setCustomOption('preview', $preview);

        return $this;
    }

    public function setTheme(?string $theme): self
    {
        $this->setFormTypeOption('theme', $theme);

        return $this;
    }

    public function setVariant(string $variant): self
    {
        $this->setFormTypeOption('variant', $variant);

        return $this;
    }

    public function setCustomTemplate(?string $template): self
    {
        $this->setFormTypeOption('template', $template);

        return $this;
    }

    public function setButtonLabel(?string $label): self
    {
        $this->setFormTypeOption('button_label', $label);

        return $this;
    }

    public function setPlaceholder(?string $placeholder): self
    {
        $this->setFormTypeOption('placeholder', $placeholder);

        return $this;
    }

    public function setColorScheme(?string $colorScheme): self
    {
        $this->setFormTypeOption('color_scheme', $colorScheme);

        return $this;
    }
}
