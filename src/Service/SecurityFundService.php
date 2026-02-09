<?php

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

class SecurityFundService
{
    private const CONFIG_FILE = __DIR__ . '/../../var/security_fund.yaml';
    private const KEY = 'security_fund_balance';
    
    /**
     * Get the security fund balance
     */
    public function getBalance(): float
    {
        if (!file_exists(self::CONFIG_FILE)) {
            return 0.0;
        }
        
        $content = file_get_contents(self::CONFIG_FILE);
        if ($content === false) {
            return 0.0;
        }
        
        $config = Yaml::parse($content);
        return is_array($config) && isset($config[self::KEY]) ? (float)$config[self::KEY] : 0.0;
    }
    
    /**
     * Set the security fund balance
     */
    public function setBalance(float $amount): void
    {
        $config = [
            self::KEY => $amount,
        ];
        
        $yaml = Yaml::dump($config);
        file_put_contents(self::CONFIG_FILE, $yaml);
    }
    
    /**
     * Add amount to security fund
     */
    public function add(float $amount): float
    {
        $newBalance = $this->getBalance() + $amount;
        $this->setBalance($newBalance);
        return $newBalance;
    }
    
    /**
     * Subtract amount from security fund
     */
    public function subtract(float $amount): float
    {
        $newBalance = $this->getBalance() - $amount;
        $this->setBalance($newBalance);
        return $newBalance;
    }
    
    /**
     * Reset security fund to 0
     */
    public function reset(): void
    {
        $this->setBalance(0.0);
    }
    
    /**
     * Check if sufficient balance exists
     */
    public function hasSufficientBalance(float $amount): bool
    {
        return $this->getBalance() >= $amount;
    }
}

