<?php

namespace App\Contracts;

interface FootballDataProvider
{
    /** @return array{home: float, away: float} Expected goals, not probabilities. */
    public function expectedGoals(array $fixture): array;
}
