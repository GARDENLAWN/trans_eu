<?php
namespace GardenLawn\TransEu\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use GardenLawn\TransEu\Model\TokenProvider;
use GardenLawn\TransEu\Model\AuthService;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\State;

class RefreshTokenCommand extends Command
{
    protected $tokenProvider;
    protected $configWriter;
    protected $cacheTypeList;
    protected $reinitableConfig;
    protected $state;

    public function __construct(
        TokenProvider $tokenProvider,
        WriterInterface $configWriter,
        TypeListInterface $cacheTypeList,
        ReinitableConfigInterface $reinitableConfig,
        State $state
    ) {
        $this->tokenProvider = $tokenProvider;
        $this->configWriter = $configWriter;
        $this->cacheTypeList = $cacheTypeList;
        $this->reinitableConfig = $reinitableConfig;
        $this->state = $state;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('gardenlawn:transeu:refresh_token')
             ->setDescription('Refreshes Trans.eu token via Python script');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->state->setAreaCode('adminhtml'); // Required for some config operations
        } catch (\Exception $e) {}

        $output->writeln("Starting token refresh...");

        $token = $this->tokenProvider->getTokenFromPython();

        if ($token) {
            $this->configWriter->save(AuthService::XML_PATH_MANUAL_TOKEN, $token);
            $this->cacheTypeList->cleanType('config');
            $this->reinitableConfig->reinit();

            $output->writeln("<info>Token refreshed successfully.</info>");
            $output->writeln("Token: " . substr($token, 0, 20) . "...");
            return 0;
        } else {
            $output->writeln("<error>Failed to refresh token.</error>");
            return 1;
        }
    }
}
