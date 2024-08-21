<?php

declare(strict_types=1);

namespace racacax\XmlTv\Component;

use racacax\XmlTv\StaticComponent\ChannelInformation;
use racacax\XmlTv\ValueObject\DummyChannel;

use function Amp\async;
use function Amp\delay;

class Generator
{
    /**
     * @var array
     */
    private array $extraParams;

    /**
     * @var array
     */
    private array $listDate = [];
    /**
     * @var bool
     */
    private bool $createEpgIfNotFound;
    /**
     * @var XmlExporter
     */
    private XmlExporter $exporter;
    /**
     * @var XmlFormatter
     */
    private XmlFormatter $formatter;
    /**
     * @var CacheFile
     */
    private CacheFile $cache;
    /**
     * @var int
     */
    private int $threads;

    public function __construct(\DateTimeImmutable $start, \DateTimeImmutable $stop, bool $createEpgIfNotFound, int $threads, array $extraParams)
    {
        $this->createEpgIfNotFound = $createEpgIfNotFound;
        $this->extraParams = $extraParams;
        $this->threads = $threads;
        $current = new \DateTime();
        $current->setTimestamp($start->getTimestamp());
        while ($current <= $stop) {
            $this->listDate[] = $current->format('Y-m-d');
            $current->modify('+1 day');
        }
    }


    public $guides;
    /**
     * @var ProviderInterface[] list of all provider
     */
    private array $providers;

    public function addGuides(array $guidesAsArray)
    {
        $this->guides = $guidesAsArray;
    }

    /**
     * @param ProviderInterface[] $providers
     */
    public function setProviders(array $providers)
    {
        $this->providers = $providers;
    }

    /**
     * @return ProviderInterface[]
     */
    public function getProviders(array $list = []): array
    {
        if (empty($list)) {
            return $this->providers;
        }

        return array_filter(
            $this->providers,
            function (ProviderInterface $provider) use ($list) {
                return
                   in_array(Utils::extractProviderName($provider), $list, true) ||
                   in_array(get_class($provider), $list, true)
                ;
            }
        );
    }

    public function getExtraParams()
    {
        return $this->extraParams;
    }

    private function generateEpgSingleThread()
    {
        $logsFinal = [];
        foreach ($this->guides as $guide) {
            $channels = json_decode(file_get_contents($guide['channels']), true);
            Logger::log(sprintf("\e[95m[EPG GRAB] \e[39mRécupération du guide des programmes (%s - %d chaines)\n", $guide['channels'], count($channels)));


            $logs = ['channels' => [], 'xml' => [],'failed_providers' => []];
            $countChannel = 0;
            foreach ($channels as $channelKey => $channelInfo) {
                $countChannel++;
                $providers = $this->getProviders($channelInfo['priority'] ?? []);
                foreach ($this->listDate as $date) {
                    $cacheKey = sprintf('%s_%s.xml', $channelKey, $date);
                    if (!isset($logs['channels'][$date][$channelKey])) {
                        $logs['channels'][$date][$channelKey] = [
                            'success' => false,
                            'provider' => null,
                            'cache' => false,
                            'failed_providers' => [],
                        ];
                    }
                    Logger::log(sprintf("\e[95m[EPG GRAB] \e[39m%s (%d/%d) : %s", $channelKey, $countChannel, count($channels), $date));

                    if ($this->cache->has($cacheKey)) {
                        Logger::log(" | \e[33mOK \e[39m- From Cache ".chr(10));
                        $logs['channels'][$date][$channelKey]['success'] = true;
                        $logs['channels'][$date][$channelKey]['cache'] = true;

                        continue;
                    }
                    $channelFound = false;
                    foreach ($providers as $provider) {
                        $old_zone = date_default_timezone_get();

                        try {
                            $channel = @$provider->constructEPG($channelKey, $date);
                        } catch(\Throwable $e) {
                            $channel = false;
                        }
                        date_default_timezone_set($old_zone);
                        if ($channel === false || $channel->getProgramCount() === 0) {
                            $logs['channels'][$date][$channelKey]['failed_providers'][] = get_class($provider);
                            $logs['failed_providers'][get_class($provider)] = true;

                            continue;
                        }

                        $channelFound = true;
                        $logs['channels'][$date][$channelKey] = [
                            'success' => true,
                            'provider' => get_class($provider),
                            'cache' => false,
                        ];
                        $this->cache->store($cacheKey, $this->formatter->formatChannel($channel, $provider));
                        Logger::log(" | \e[32mOK\e[39m - ".Utils::extractProviderName($provider).chr(10));

                        break ;
                    }

                    if (!$channelFound) {
                        if ($this->createEpgIfNotFound) {
                            $this->cache->store($cacheKey, $this->formatter->formatChannel(new DummyChannel($channelKey, $date), null));
                        }
                        Logger::log(" | \e[31mHS\e[39m".chr(10));
                    }
                }
            }
            Logger::log("\e[95m[EPG GRAB] \e[39mRécupération du guide des programmes terminée...\n");
            $logsFinal[$guide['channels']] = $logs;
        }
        Logger::debug(json_encode($logsFinal));
    }

    private function generateEpgMultithread()
    {
        // TODO : reinstate Daily cache
        $fn = function () {
            $logsFinal = [];
            $logLevel = Logger::getLogLevel();
            Logger::setLogLevel('none');
            foreach ($this->guides as $guide) {
                $channels = json_decode(file_get_contents($guide['channels']), true);
                Logger::log(sprintf("\e[95m[EPG GRAB] \e[39mRécupération du guide des programmes (%s - %d chaines)\n", $guide['channels'], count($channels)));

                $threads = [];
                $manager = new ChannelsManager($channels, $this);
                for($i = 0; $i < $this->threads; $i++) {
                    $threads[] = new ChannelThread($manager, $this);
                }

                $view = function () use ($threads, $manager, $guide, $logLevel) {
                    if($logLevel != 'none') {
                        while ($manager->hasRemainingChannels() || Utils::hasOneThreadRunning($threads)) {
                            echo chr(27).chr(91).'H'.chr(27).chr(91).'J';
                            echo Utils::colorize("XML TV Fr - Génération des fichiers XMLTV\n", 'light blue');
                            echo Utils::colorize('Chaines récupérées : ', 'cyan').$manager->getStatus().'   |   '.
                                Utils::colorize('Fichier :', 'cyan')." {$guide['channels']}\n";
                            $i = 1;
                            foreach($threads as $thread) {
                                echo "Thread $i : ";
                                echo $thread->getString();
                                echo "\n";
                                $i++;
                            }
                            delay(0.1);
                        }
                    }
                };
                async($view);
                $threadsStack = array_values($threads);
                while ($manager->hasRemainingChannels() || Utils::hasOneThreadRunning($threads)) { // Necessary if one channel fails

                    delay(0.01);
                    for($i = 0; $i < count($threads); $i++) {
                        $thread = $threadsStack[0];
                        unset($threadsStack[0]);
                        $threadsStack[] = $thread;
                        $threadsStack = array_values($threadsStack);
                        if(!$thread->isRunning()) {
                            $channelData = $manager->shiftChannel();
                            if(empty($channelData)) {
                                break;
                            }
                            $thread->setChannel($channelData);
                            $thread->start();
                        }
                    }
                }

                Logger::log("\e[95m[EPG GRAB] \e[39mRécupération du guide des programmes terminée...\n");
                $logsFinal[$guide['channels']] = $manager->getLogs();
            }
            Logger::debug(json_encode($logsFinal));
        };
        $future = async($fn);
        $future->await();


    }

    public function generateEpg()
    {
        if($this->threads == 1) {
            $this->generateEpgSingleThread();
        } else {
            $this->generateEpgMultithread();
        }
    }

    public function getCache(): CacheFile
    {
        return $this->cache;
    }

    public function createEpgIfNotFound(): bool
    {
        return $this->createEpgIfNotFound;
    }

    public function getFormatter(): XmlFormatter
    {
        return $this->formatter;
    }

    public function getListDate(): array
    {
        return $this->listDate;
    }

    public function exportEpg(string $exportPath)
    {
        @mkdir($exportPath, 0777, true);

        foreach ($this->guides as $guide) {
            $channels = json_decode(file_get_contents($guide['channels']), true);
            $defaultInfo = ChannelInformation::getInstance();
            $this->exporter->startExport($exportPath . $guide['filename']);
            $listCacheKey = [];
            $listAliases = [];
            foreach ($channels as $channelKey => $channelInfo) {
                $icon = $channelInfo['icon'] ?? $defaultInfo->getDefaultIcon($channelKey);
                $name = $channelInfo['name'] ?? $defaultInfo->getDefaultName($channelKey) ?? $channelKey;
                $alias = $channelInfo['alias'] ?? $channelKey;
                if($alias != $channelKey) {
                    $listAliases[$channelKey] = $alias;
                }
                $this->exporter->addChannel($alias, $name, $icon);
                $listCacheKey = array_merge($listCacheKey, array_map(
                    function (string $date) use ($channelKey) {
                        return sprintf('%s_%s.xml', $channelKey, $date);
                    },
                    $this->listDate
                ));
            }
            foreach ($listCacheKey as $keyCache) {
                if (!$this->cache->has($keyCache)) {
                    continue;
                }
                $cache = $this->cache->get($keyCache);
                $channelId = explode('_', $keyCache)[0];
                if(array_key_exists($channelId, $listAliases)) {
                    $cache = str_replace('channel="'.$channelId.'"', 'channel="'.$listAliases[$channelId].'"', $cache);
                }
                $this->exporter->addProgramsAsString(
                    $cache
                );
            }
            $this->exporter->stopExport();
        }
    }

    public function setExporter(XmlExporter $exporter)
    {
        $this->exporter = $exporter;
        $this->formatter = $exporter->getFormatter();
    }


    public function setCache(CacheFile $cache)
    {
        $this->cache = $cache;
    }

    public function clearCache(int $maxCacheDay)
    {
        $this->cache->clearCache($maxCacheDay);
    }
}
