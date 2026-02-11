<?php

namespace App\DataFixtures;

use App\Entity\Crypto;
use App\Service\CryptoApiService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class CryptoFixtures extends Fixture
{
    private CryptoApiService $cryptoApiService;

    public function __construct(CryptoApiService $cryptoApiService)
    {
        $this->cryptoApiService = $cryptoApiService;
    }

    public function load(ObjectManager $manager): void
    {
        $cryptosConfig = [
            [
                'name' => 'Bitcoin',
                'symbol' => 'BTC',
                'apiid' => 'bitcoin',
            ],
            [
                'name' => 'Ethereum',
                'symbol' => 'ETH',
                'apiid' => 'ethereum',
            ],
            [
                'name' => 'Solana',
                'symbol' => 'SOL',
                'apiid' => 'solana',
            ],
            [
                'name' => 'Cardano',
                'symbol' => 'ADA',
                'apiid' => 'cardano',
            ],
            [
                'name' => 'Ripple',
                'symbol' => 'XRP',
                'apiid' => 'ripple',
            ],
            [
                'name' => 'Polkadot',
                'symbol' => 'DOT',
                'apiid' => 'polkadot',
            ],
            [
                'name' => 'Dogecoin',
                'symbol' => 'DOGE',
                'apiid' => 'dogecoin',
            ],
        ];

        // récupérer les prix depuis l'API
        $prices = $this->cryptoApiService->getPrices(
            array_column($cryptosConfig, 'apiid')
        );

        foreach ($cryptosConfig as $cryptoData) {
            $crypto = new Crypto();
            $crypto->setName($cryptoData['name']);
            $crypto->setSymbol($cryptoData['symbol']);
            $crypto->setApiid($cryptoData['apiid']);
            $crypto->setCurrentprice($prices[$cryptoData['apiid']]['usd']);

            $manager->persist($crypto);
        }

        $manager->flush();
    }
}
