<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ProductAsset;
use App\Service\Media\ProductMediaStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/media/product')]
final class ProductAssetController extends AbstractController
{
    #[Route('/{id}', name: 'app_product_asset_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(ProductAsset $asset, ProductMediaStorage $mediaStorage): BinaryFileResponse
    {
        $response = new BinaryFileResponse($mediaStorage->resolveAbsolutePath($asset));
        $response->headers->set('Content-Type', $asset->getMimeType());
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $asset->getOriginalName());

        return $response;
    }
}
