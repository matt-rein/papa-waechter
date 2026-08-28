<?php

namespace App\Controller;

use App\Repository\CheckInRepository;
use App\Repository\WatcheeRepository;
use App\Service\MonitoringStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly string $displayTimezone,
        private readonly int $alertThresholdHours,
        private readonly int $alertReminderHours,
    ) {
    }

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function index(
        WatcheeRepository $watchees,
        CheckInRepository $checkIns,
        MonitoringStatus $status,
    ): Response {
        $items = [];
        foreach ($watchees->findAll() as $watchee) {
            $items[] = [
                'status' => $status->for($watchee),
                'recent' => $checkIns->findRecentFor($watchee, 30),
            ];
        }

        return $this->render('dashboard/index.html.twig', [
            'items' => $items,
            'timezone' => $this->displayTimezone,
            // Für die Bedienungsanleitung: die tatsächlich konfigurierten Fristen.
            'thresholdHours' => $this->alertThresholdHours,
            'reminderHours' => $this->alertReminderHours,
        ]);
    }
}
