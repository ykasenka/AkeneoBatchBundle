<?php

declare(strict_types=1);

namespace Akeneo\Tool\Bundle\BatchBundle\Command;

use Akeneo\Tool\Bundle\BatchBundle\Notification\MailNotifier;
use Akeneo\Tool\Component\Batch\Item\ExecutionContext;
use Akeneo\Tool\Component\Batch\Job\ExitStatus;
use Akeneo\Tool\Component\Batch\Job\JobParameters;
use Akeneo\Tool\Component\Batch\Job\JobParametersFactory;
use Akeneo\Tool\Component\Batch\Job\JobParametersValidator;
use Akeneo\Tool\Component\Batch\Job\JobRegistry;
use Akeneo\Tool\Component\Batch\Model\JobInstance;
use Akeneo\Tool\Component\Batch\Model\StepExecution;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Monolog\Handler\ConsoleHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Batch command
 *
 * @author    Benoit Jacquemont <benoit@akeneo.com>
 * @copyright 2013 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/MIT MIT
 */
class BatchCommand extends Command implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    const EXIT_SUCCESS_CODE = 0;
    const EXIT_ERROR_CODE = 1;
    const EXIT_WARNING_CODE = 2;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('akeneo:batch:job')
            ->setDescription(
                '[DEPRECATED] Please use "akeneo:batch:publish-job-to-queue" to launch a registered job instance'
            )
            ->addArgument('code', InputArgument::REQUIRED, 'Job instance code')
            ->addArgument('execution', InputArgument::OPTIONAL, 'Job execution id')
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Override job configuration (formatted as json. ie: ' .
                'php bin/console akeneo:batch:job -c "{\"filePath\":\"/tmp/foo.csv\"}" acme_product_import)'
            )
            ->addOption(
                'username',
                null,
                InputOption::VALUE_REQUIRED,
                'Username to launch the job instance with'
            )
            ->addOption(
                'email',
                null,
                InputOption::VALUE_REQUIRED,
                'The email to notify at the end of the job execution'
            )
            ->addOption(
                'no-log',
                null,
                InputOption::VALUE_NONE,
                'Don\'t display logs'
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $noLog = $input->getOption('no-log');

        if (!$noLog) {
            $logger = $this->container->get('monolog.logger.batch');
            $logger->pushHandler(new ConsoleHandler($output));
        }

        $code = $input->getArgument('code');
        $jobInstanceClass = $this->container->getParameter('akeneo_batch.entity.job_instance.class');
        $jobInstance = $this->getJobManager()->getRepository($jobInstanceClass)->findOneBy(['code' => $code]);

        if (null === $jobInstance) {
            throw new \InvalidArgumentException(sprintf('Could not find job instance "%s".', $code));
        }

        $validator = $this->getValidator();
        // Override mail notifier recipient email
        if ($email = $input->getOption('email')) {
            $errors = $validator->validate($email, new Assert\Email());
            if (count($errors) > 0) {
                throw new \RuntimeException(
                    sprintf('Email "%s" is invalid: %s', $email, $this->getErrorMessages($errors))
                );
            }
            $this->getMailNotifier()->setRecipientEmail($email);
        }

        $job = $this->getJobRegistry()->get($jobInstance->getJobName());
        $executionId = $input->hasArgument('execution') ? $input->getArgument('execution') : null;

        if (null !== $executionId && null !== $input->getOption('config')) {
            throw new \InvalidArgumentException('Configuration option cannot be specified when launching a job execution.');
        }

        if (null !== $executionId && $input->hasOption('username') && null !== $input->getOption('username')) {
            throw new \InvalidArgumentException('Username option cannot be specified when launching a job execution.');
        }

        if (null === $executionId) {
            $jobParameters = $this->createJobParameters($jobInstance, $input);
            $this->validateJobParameters($jobInstance, $jobParameters, $code);
            $jobExecution = $job->getJobRepository()->createJobExecution($jobInstance, $jobParameters);

            $username = $input->getOption('username');
            if (null !== $username) {
                $jobExecution->setUser($username);
                $job->getJobRepository()->updateJobExecution($jobExecution);
            }
        } else {
            $jobExecutionClass = $this->container->getParameter('akeneo_batch.entity.job_execution.class');
            $jobExecution = $this->getJobManager()->getRepository($jobExecutionClass)->find($executionId);
            if (!$jobExecution) {
                throw new \InvalidArgumentException(sprintf('Could not find job execution "%s".', $executionId));
            }
            if (!$jobExecution->getStatus()->isStarting()) {
                throw new \RuntimeException(
                    sprintf('Job execution "%s" has invalid status: %s', $executionId, $jobExecution->getStatus())
                );
            }
            if (null === $jobExecution->getExecutionContext()) {
                $jobExecution->setExecutionContext(new ExecutionContext());
            }
        }

        $jobExecution->setPid(getmypid());
        $job->getJobRepository()->updateJobExecution($jobExecution);

        $this
            ->container
            ->get('akeneo_batch.logger.batch_log_handler')
            ->setSubDirectory($jobExecution->getId());

        $job->execute($jobExecution);

        $job->getJobRepository()->updateJobExecution($jobExecution);

        $verbose = $input->getOption('verbose');
        $exitCode = null;
        if (ExitStatus::COMPLETED === $jobExecution->getExitStatus()->getExitCode()) {
            $nbWarnings = 0;
            /** @var StepExecution $stepExecution */
            foreach ($jobExecution->getStepExecutions() as $stepExecution) {
                $nbWarnings += count($stepExecution->getWarnings());
                if ($verbose) {
                    foreach ($stepExecution->getWarnings() as $warning) {
                        $output->writeln(sprintf('<comment>%s</comment>', $warning->getReason()));
                    }
                }
            }

            if (0 === $nbWarnings) {
                $output->writeln(
                    sprintf(
                        '<info>%s %s has been successfully executed.</info>',
                        ucfirst($jobInstance->getType()),
                        $jobInstance->getCode()
                    )
                );

                $exitCode = self::EXIT_SUCCESS_CODE;
            } else {
                $output->writeln(
                    sprintf(
                        '<comment>%s %s has been executed with %d warnings.</comment>',
                        ucfirst($jobInstance->getType()),
                        $jobInstance->getCode(),
                        $nbWarnings
                    )
                );

                $exitCode = self::EXIT_WARNING_CODE;
            }
        } else {
            $output->writeln(
                sprintf(
                    '<error>An error occurred during the %s execution.</error>',
                    $jobInstance->getType()
                )
            );
            $this->writeExceptions($output, $jobExecution->getFailureExceptions(), $verbose);
            foreach ($jobExecution->getStepExecutions() as $stepExecution) {
                $this->writeExceptions($output, $stepExecution->getFailureExceptions(), $verbose);
            }

            $exitCode = self::EXIT_ERROR_CODE;
        }

        return $exitCode;
    }

    /**
     * Writes failure exceptions to the output
     *
     * @param OutputInterface $output
     * @param array[]         $exceptions
     * @param boolean         $verbose
     */
    protected function writeExceptions(OutputInterface $output, array $exceptions, $verbose)
    {
        foreach ($exceptions as $exception) {
            $output->write(
                sprintf(
                    '<error>Error #%s in class %s: %s</error>',
                    $exception['code'],
                    $exception['class'],
                    strtr($exception['message'], $exception['messageParameters'])
                ),
                true
            );
            if ($verbose) {
                $output->write(sprintf('<error>%s</error>', $exception['trace']), true);
            }
        }
    }

    /**
     * @return EntityManagerInterface
     */
    protected function getJobManager(): EntityManagerInterface
    {
        return $this->container->get('akeneo_batch.job_repository')->getJobManager();
    }

    /**
     * @return EntityManagerInterface
     */
    protected function getDefaultEntityManager(): EntityManagerInterface
    {
        return $this->container->get('doctrine')->getManager();
    }

    /**
     * @return ValidatorInterface
     */
    protected function getValidator(): ValidatorInterface
    {
        return $this->container->get('validator');
    }

    /**
     * @return MailNotifier
     */
    protected function getMailNotifier(): MailNotifier
    {
        return $this->container->get('akeneo_batch.mail_notifier');
    }

    /**
     * @return JobRegistry
     */
    protected function getJobRegistry(): JobRegistry
    {
        return $this->container->get('akeneo_batch.job.job_registry');
    }

    /**
     * @return JobParametersFactory
     */
    protected function getJobParametersFactory(): JobParametersFactory
    {
        return $this->container->get('akeneo_batch.job_parameters_factory');
    }

    /**
     * @return JobParametersValidator
     */
    protected function getJobParametersValidator(): JobParametersValidator
    {
        return $this->container->get('akeneo_batch.job.job_parameters_validator');
    }

    /**
     * @param JobInstance    $jobInstance
     * @param InputInterface $input
     *
     * @return JobParameters
     */
    protected function createJobParameters(JobInstance $jobInstance, InputInterface $input): JobParameters
    {
        $job = $this->getJobRegistry()->get($jobInstance->getJobName());
        $jobParamsFactory = $this->getJobParametersFactory();
        $rawParameters = $jobInstance->getRawParameters();

        $config = $input->getOption('config') ? $this->decodeConfiguration($input->getOption('config')) : [];

        $rawParameters = array_merge($rawParameters, $config);
        $jobParameters = $jobParamsFactory->create($job, $rawParameters);

        return $jobParameters;
    }

    /**
     * @param JobInstance   $jobInstance
     * @param JobParameters $jobParameters
     * @param string        $code
     *
     * @throws \RuntimeException
     */
    protected function validateJobParameters(JobInstance $jobInstance, JobParameters $jobParameters, string $code) : void
    {
        // We merge the JobInstance from the JobManager EntityManager to the DefaultEntityManager
        // in order to be able to have a working UniqueEntity validation
        $defaultJobInstance = $this->getDefaultEntityManager()->merge($jobInstance);
        $job = $this->getJobRegistry()->get($jobInstance->getJobName());
        $paramsValidator = $this->getJobParametersValidator();
        $errors = $paramsValidator->validate($job, $jobParameters, ['Default', 'Execution']);

        if (count($errors) > 0) {
            throw new \RuntimeException(
                sprintf(
                    'Job instance "%s" running the job "%s" with parameters "%s" is invalid because of "%s"',
                    $code,
                    $job->getName(),
                    print_r($jobParameters->all(), true),
                    $this->getErrorMessages($errors)
                )
            );
        }
    }

    /**
     * @param ConstraintViolationList $errors
     *
     * @return string
     */
    private function getErrorMessages(ConstraintViolationList $errors): string
    {
        $errorsStr = '';

        foreach ($errors as $error) {
            $errorsStr .= sprintf("\n  - %s", $error);
        }

        return $errorsStr;
    }

    /**
     * @param string $data
     *
     * @throws \InvalidArgumentException
     *
     * @return array
     */
    private function decodeConfiguration($data): array
    {
        $config = json_decode($data, true);

        switch (json_last_error()) {
            case JSON_ERROR_DEPTH:
                $error = 'Maximum stack depth exceeded';
                break;
            case JSON_ERROR_STATE_MISMATCH:
                $error = 'Underflow or the modes mismatch';
                break;
            case JSON_ERROR_CTRL_CHAR:
                $error = 'Unexpected control character found';
                break;
            case JSON_ERROR_SYNTAX:
                $error = 'Syntax error, malformed JSON';
                break;
            case JSON_ERROR_UTF8:
                $error = 'Malformed UTF-8 characters, possibly incorrectly encoded';
                break;
            default:
                return $config;
        }

        throw new \InvalidArgumentException($error);
    }
}
