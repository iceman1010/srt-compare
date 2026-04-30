<?php

namespace App;

use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Subtitles\Exception\InvalidSubtitleException;

class Application extends ConsoleApplication
{
    public function __construct()
    {
        parent::__construct('srt-compare', $this->getVersion());

        // Add the default command
        $compareCommand = new class extends Command {
            protected function configure(): void
            {
                $this
                    ->setName('compare')
                    ->setDescription('Compare two subtitle files')
                    ->addOption(
                        'file',
                        'i',
                        InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                        'Subtitle file'
                    )
                    ->addOption(
                        'update',
                        null,
                        InputOption::VALUE_NONE,
                        'Update the application to the latest version from GitHub'
                    );
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $io = new SymfonyStyle($input, $output);
                
                // Handle the update option
                if ($input->getOption('update')) {
                    return $this->selfUpdate($io);
                }
                
                $files = $input->getOption('file');

                // Validate that exactly two files are provided
                if ($files === null || count($files) !== 2) {
                    $io->error('Exactly two subtitle files are required. Use -i <file1> -i <file2>');
                    return Command::FAILURE;
                }
                
                list($file1, $file2) = $files;

                // Validate files exist
                if (!is_readable($file1)) {
                    $io->error(sprintf('File not found or not readable: %s', $file1));
                    return Command::FAILURE;
                }
                
                if (!is_readable($file2)) {
                    $io->error(sprintf('File not found or not readable: %s', $file2));
                    return Command::FAILURE;
                }

                $io->title('Subtitle Compare');
                $io->text(sprintf('Comparing %s with %s', $file1, $file2));
                
                // Load and compare subtitles
                try {
                    $comparator = new \App\SubtitleComparator($file1, $file2);
                    $comparisonData = $comparator->getComparisonData();
                } catch (InvalidSubtitleException $e) {
                    $io->error(sprintf('Invalid subtitle file: %s', $e->getMessage()));
                    return Command::FAILURE;
                } catch (\Exception $e) {
                    $io->error(sprintf('Error loading subtitle files: %s', $e->getMessage()));
                    return Command::FAILURE;
                }
                
                // Render UI
                $ui = new \App\TerminalUI($comparisonData);
                $ui->render();
                
                return Command::SUCCESS;
            }
            
            private function selfUpdate(SymfonyStyle $io): int
            {
                $io->title('Self Update');
                
                // Get current version
                $currentVersion = trim(file_get_contents(__DIR__ . '/../VERSION'));
                $io->text(sprintf('Current version: %s', $currentVersion));
                
                // Fetch latest version from GitHub
                $latestVersionUrl = 'https://api.github.com/repos/iceman1010/srt-compare/releases/latest';
                
                $io->text('Checking for latest version...');
                
                $ch = curl_init($latestVersionUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERAGENT, 'srt-compare-self-update');
                
                // Add GitHub token if available (for higher rate limits)
                $githubToken = getenv('GITHUB_TOKEN');
                if ($githubToken) {
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: token ' . $githubToken]);
                }
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode !== 200) {
                    $io->error('Failed to check for updates. Please try again later.');
                    return Command::FAILURE;
                }
                
                $data = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $io->error('Invalid response from GitHub.');
                    return Command::FAILURE;
                }
                
                $latestVersion = $data['tag_name'] ?? $data['name'] ?? '';
                if (!$latestVersion) {
                    $io->error('Could not determine latest version.');
                    return Command::FAILURE;
                }
                
                // Remove 'v' prefix if present
                $latestVersion = ltrim($latestVersion, 'v');
                
                $io->text(sprintf('Latest version: %s', $latestVersion));
                
                if (version_compare($latestVersion, $currentVersion, '>')) {
                    $io->text('Update available! Downloading...');
                    
                    // Find the PHAR asset
                    $pharAssetUrl = null;
                    foreach ($data['assets'] as $asset) {
                        if (str_ends_with($asset['name'], '.phar')) {
                            $pharAssetUrl = $asset['browser_download_url'];
                            break;
                        }
                    }
                    
                    if (!$pharAssetUrl) {
                        $io->error('Could not find PHAR asset in latest release.');
                        return Command::FAILURE;
                    }
                    
                    // Download the new PHAR
                    $tempFile = sys_get_temp_dir() . '/srt-compare-new.phar';
                    $io->progressStart(100);
                    
                    $ch = curl_init($pharAssetUrl);
                    $fp = fopen($tempFile, 'w');
                    
                    curl_setopt($ch, CURLOPT_FILE, $fp);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_USERAGENT, 'srt-compare-self-update');
                    
                    if ($githubToken) {
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: token ' . $githubToken]);
                    }
                    
                    // Progress callback
                    curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, function($ch, $dltotal, $dlnow, $ultotal, $ulnow) use ($io) {
                        if ($dltotal > 0) {
                            $progress = intval(($dlnow / $dltotal) * 100);
                            $io->progressAdvance($progress - $io->getProgressStep());
                        }
                        return 0;
                    });
                    
                    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
                    
                    $result = curl_exec($ch);
                    $curlError = curl_error($ch);
                    curl_close($ch);
                    fclose($fp);
                    
                    $io->progressFinish();
                    
                    if ($curlError) {
                        $io->error('Download failed: ' . $curlError);
                        @unlink($tempFile);
                        return Command::FAILURE;
                    }
                    
                    if (!$result) {
                        $io->error('Download failed.');
                        @unlink($tempFile);
                        return Command::FAILURE;
                    }
                    
                    // Make executable
                    chmod($tempFile, 0755);
                    
                    // Replace current PHAR
                    $currentPhar = __DIR__ . '/../srt-compare.phar';
                    if (rename($tempFile, $currentPhar)) {
                        // Update VERSION file
                        file_put_contents(__DIR__ . '/../VERSION', $latestVersion);
                        $io->success(sprintf('Updated to version %s!', $latestVersion));
                        $io->text('Please restart the application to use the new version.');
                        return Command::SUCCESS;
                    } else {
                        $io->error('Failed to replace the current PHAR file.');
                        @unlink($tempFile);
                        return Command::FAILURE;
                    }
                } else {
                    $io->success('You are already running the latest version!');
                    return Command::SUCCESS;
                }
            }
        };

        $this->add($compareCommand);
        $this->setDefaultCommand('compare', true);
    }
    
    public function getVersion(): string
    {
        $versionFile = __DIR__ . '/../VERSION';
        if (file_exists($versionFile)) {
            return trim(file_get_contents($versionFile));
        }
        return '1.0.0';
    }
}