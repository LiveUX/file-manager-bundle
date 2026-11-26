<?php

declare(strict_types=1);

use LiveUX\FileManagerBundle\Controller\ApiController;
use LiveUX\FileManagerBundle\Controller\DownloadController;
use LiveUX\FileManagerBundle\Controller\ImageEditorController;
use LiveUX\FileManagerBundle\Controller\ShareController;
use LiveUX\FileManagerBundle\Controller\ThumbnailController;
use LiveUX\FileManagerBundle\Controller\UploadController;
use LiveUX\FileManagerBundle\Controller\ZipDownloadController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('liveux_file_manager_dialog', '/_liveux-file-manager/dialog')
        ->controller([ApiController::class, 'dialog'])
        ->methods(['GET']);

    $routes->add('liveux_file_manager_api_list', '/_liveux-file-manager/api/list')
        ->controller([ApiController::class, 'list'])
        ->defaults(['_format' => 'json'])
        ->methods(['GET']);

    $routes->add('liveux_file_manager_api_tree', '/_liveux-file-manager/api/tree')
        ->controller([ApiController::class, 'tree'])
        ->defaults(['_format' => 'json'])
        ->methods(['GET']);

    $routes->add('liveux_file_manager_api_trash', '/_liveux-file-manager/api/trash')
        ->controller([ApiController::class, 'trash'])
        ->defaults(['_format' => 'json'])
        ->methods(['GET']);

    $routes->add('liveux_file_manager_api_preview_text', '/_liveux-file-manager/api/preview-text')
        ->controller([ApiController::class, 'previewText'])
        ->defaults(['_format' => 'json'])
        ->methods(['GET']);

    $routes->add('liveux_file_manager_api_action', '/_liveux-file-manager/api/action')
        ->controller([ApiController::class, 'action'])
        ->defaults(['_format' => 'json'])
        ->methods(['POST']);

    $routes->add('liveux_file_manager_upload', '/_liveux-file-manager/upload')
        ->controller(UploadController::class)
        ->defaults(['_format' => 'json'])
        ->methods(['POST']);

    $routes->add('liveux_file_manager_image_edit', '/_liveux-file-manager/image-edit')
        ->controller(ImageEditorController::class)
        ->defaults(['_format' => 'json'])
        ->methods(['POST']);

    $routes->add('liveux_file_manager_download', '/_liveux-file-manager/download')
        ->controller(DownloadController::class)
        ->methods(['GET']);

    $routes->add('liveux_file_manager_share', '/_liveux-file-manager/share')
        ->controller(ShareController::class)
        ->methods(['GET']);

    $routes->add('liveux_file_manager_zip', '/_liveux-file-manager/zip')
        ->controller(ZipDownloadController::class)
        ->methods(['POST']);

    $routes->add('liveux_file_manager_thumb', '/_liveux-file-manager/thumb')
        ->controller(ThumbnailController::class)
        ->methods(['GET']);
};
