<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Platform\PlatformOverviewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function __invoke(PlatformOverviewBuilder $overviewBuilder): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'overview' => $overviewBuilder->build(),
        ]);
    }
}
