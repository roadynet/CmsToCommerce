<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Configuration\ChannelCredentialManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route('/credentials')]
final class CredentialController extends AbstractController
{
    public function __construct(
        private readonly ChannelCredentialManager $channelCredentialManager,
    ) {
    }

    #[Route('', name: 'app_credentials_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('app_credentials_show', ['channel' => 'shopware']);
    }

    #[Route('/{channel}', name: 'app_credentials_show', methods: ['GET', 'POST'])]
    public function show(string $channel, Request $request): Response
    {
        try {
            $activeChannel = $this->channelCredentialManager->channel($channel);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Unbekannter Zugangsdaten-Channel.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('credentials_'.$channel, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Ungueltiges Formular-Token.');
            }

            try {
                $result = $this->channelCredentialManager->save(
                    $channel,
                    $request->request->all('credentials'),
                );

                $message = sprintf(
                    '%s-Zugangsdaten wurden gespeichert. Datei: %s',
                    $activeChannel['label'],
                    $result['path'],
                );

                if ($result['kept'] !== []) {
                    $message .= ' Bestehende geheime Werte behalten: '.implode(', ', $result['kept']).'.';
                }

                $this->addFlash('success', $message);
            } catch (Throwable $exception) {
                $this->addFlash('error', 'Zugangsdaten konnten nicht gespeichert werden: '.$exception->getMessage());
            }

            return $this->redirectToRoute('app_credentials_show', ['channel' => $channel]);
        }

        return $this->render('credentials/show.html.twig', [
            'channels' => $this->channelCredentialManager->channels(),
            'active_channel' => $activeChannel,
            'active_slug' => $channel,
        ]);
    }
}
