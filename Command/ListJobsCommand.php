<?php

namespace Akeneo\Tool\Bundle\BatchBundle\Command;

use Akeneo\Tool\Component\Batch\Model\JobInstance;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

/**
 * Lists active batch jobs
 *
 * @author    Antoine Guigan <antoine@akeneo.com>
 * @copyright 2014 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class ListJobsCommand extends Command implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    /**
     * @staticvar string Option used to list all jobs
     */
    const LIST_ALL = 'all';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('akeneo:batch:list-jobs')
            ->setDescription('List the existing job instances')
            ->addOption(
                'type',
                't',
                InputOption::VALUE_REQUIRED,
                'The type of jobs to list (import|export|all)',
                static::LIST_ALL
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $criteria = [];
        $type = $input->getOption('type');
        if (static::LIST_ALL !== $type) {
            $criteria['type'] = $type;
        }
        $jobs = $this->getJobManager()->getRepository(JobInstance::class)
            ->findBy($criteria, ['type' => 'asc', 'code' => 'asc']);
        $table = $this->buildTable($jobs, $output);
        $table->render($output);
    }

    /**
     * @param array           $jobs
     * @param OutputInterface $output
     *
     * @return \Symfony\Component\Console\Helper\HelperInterface
     */
    protected function buildTable(array $jobs, OutputInterface $output)
    {
        $rows = [];
        foreach ($jobs as $job) {
            $rows[] = [$job->getType(), $job->getCode()];
        }
        $headers = ['type', 'code'];
        $table = new Table($output);
        $table->setHeaders($headers)->setRows($rows);

        return $table;
    }

    /**
     * @return EntityManager
     */
    protected function getJobManager()
    {
        return $this->container->get('akeneo_batch.job_repository')->getJobManager();
    }
}
