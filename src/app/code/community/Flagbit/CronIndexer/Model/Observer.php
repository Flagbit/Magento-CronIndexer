<?php
/**
 * Created by JetBrains PhpStorm.
 * User: weller
 * Date: 06.12.12
 * Time: 18:05
 * To change this template use File | Settings | File Templates.
 */

class Flagbit_CronIndexer_Model_Observer {

    protected $_factory = null;
    protected $_lastSchedule = null;

    /**
     * Get Indexer instance
     *
     * @return Mage_Index_Model_Indexer
     */
    protected function _getIndexer()
    {
        if(Mage::helper('flagbit_cronindexer')->isNewIndexerEnabled()){
            if($this->_factory === null){
                $this->_factory = new Mage_Core_Model_Factory();
            }
            return $this->_factory->getSingleton($this->_factory->getIndexClassAlias());
        }
        return Mage::getSingleton('index/indexer');
    }

    /**
     * @param $schedule
     */
    public function process($schedule)
    {
        $processIds = explode(',', $schedule->getMessages());

        $processIdsExecuted = array();
        foreach ($processIds as $processId) {
            /* @var $process Mage_Index_Model_Process */
            $process = $this->_getIndexer()->getProcessById($processId);
            if ($process) {
                try{
                    $process->reindexEverything();
                }catch (Exception $e){
                    Mage::logException($e);
                }
                $processIdsExecuted[] = $processId;
                $schedule->setMessages(implode(',', array_diff ($processIds, $processIdsExecuted)))->save();
            }
        }
    }


    /**
     * Prepare the adminhtml category products view
     *
     * @param Varien_Event_Observer $observer
     * @return void
     */
    public function adminhtmlBlockHtmlBefore(Varien_Event_Observer $observer)
    {
        /* @var $block Mage_Adminhtml_Block_Widget_Grid */
        $block = $observer->getBlock();

        if (($block instanceof Mage_Index_Block_Adminhtml_Process_Grid
            || $block instanceof Enterprise_Index_Block_Adminhtml_Process_Grid)
            && $block->getId() == 'indexer_processes_grid'
        ) {

            // add process ids
            if(Mage::helper('flagbit_cronindexer')->isNewIndexerEnabled()){
                $this->_enrichIndexCollection($block->getCollection());
            }

            $block->addColumn(
                'dynamic',
                array(
                    'header'         => Mage::helper('flagbit_cronindexer')->__('Schedule'),
                    'width'          => '80',
                    'index'          => 'schedule',
                    'sortable'       => false,
                    'filter'         => false,
                    'frame_callback' => array($this, 'decorateType')
                )
            );

            $block->getMassactionBlock()->addItem('flagbit_cronindexer', array(
                'label'    => Mage::helper('flagbit_cronindexer')->__('Reindex Data (Cronjob)'),
                'url'      => $block->getUrl('*/*/massReindexCron'),
                'selected' => true,
            ));
        }
    }

    /**
     * enrich collection with process ids and execution times
     *
     * @param $collection
     */
    protected function _enrichIndexCollection($collection)
    {
        foreach($collection as $item){
            if(is_int($item->getProcessId())){
                continue;
            }
            $indexer = Mage::getSingleton('index/indexer')->getProcessByCode($item->getIndexerCode());
            #Zend_Debug::dump($indexer->getData());
            if($indexer->getProcessId()){
                $item->setProcessId($indexer->getProcessId());
                $item->setEndedAt($this->_getLastSchedule()->getFinishedAt());
            }
        }
    }


    /**
     * get the last schedule
     *
     * @return Mage_Cron_Model_Schedule
     */
    protected function _getLastSchedule()
    {
        if($this->_lastSchedule === null){
            /* @var $collection Mage_Cron_Model_Resource_Schedule_Collection */
            $collection = Mage::getModel('cron/schedule')->getCollection();
            $collection->addFieldToFilter('job_code', array('eg' => 'flagbit_cronindexer'))
                       ->setOrder('scheduled_at', 'DESC')->setPageSize(1);

            $this->_lastSchedule  = $collection->getFirstItem();
        }
        return $this->_lastSchedule;
    }

    /**
     * Decorate Type column values
     *
     * @return string
     */
    public function decorateType($value, $row, $column, $isExport)
    {

        $class = 'grid-severity-notice';
        $value = $column->getGrid()->__('nothing planned');

        if (in_array($row->getId(), explode(',', $this->_getLastSchedule()->getMessages()))) {

            $scheduledAtTimestamp = strtotime($this->_getLastSchedule()->getScheduledAt());
            $executedAtTimestamp = strtotime($this->_getLastSchedule()->getExecutedAt());
            $finishedAtTimestamp = strtotime($this->_getLastSchedule()->getFinishedAt());


            // will be executed in the future
            if($scheduledAtTimestamp > time()){
                $class = 'grid-severity-minor';
                $value = $column->getGrid()->__('pending (%s minutes)', round(($scheduledAtTimestamp - time())/60, 0));

            // will be executed in the future
            }elseif($scheduledAtTimestamp < time() && !$this->_getLastSchedule()->getExecutedAt()){
                $class = 'grid-severity-major';
                $value = $column->getGrid()->__('pending (%s minutes)', round(($scheduledAtTimestamp - time())/60, 0));

            // is running
            }elseif($scheduledAtTimestamp < time()
                && $this->_getLastSchedule()->getExecutedAt() && $executedAtTimestamp < time()
                && !$this->_getLastSchedule()->getFinishedAt()){
                $class = 'grid-severity-minor';
                $value = $column->getGrid()->__('running (%s minutes)', round((time() - $executedAtTimestamp) /60, 0));

            // was executed
            }elseif($scheduledAtTimestamp < time()
                && $this->_getLastSchedule()->getExecutedAt() && $executedAtTimestamp < time()
                && $this->_getLastSchedule()->getFinishedAt()){
                $class = 'grid-severity-notice';
                $value = $column->getGrid()->__('finished %s', Mage::helper('core')->formatDate($this->_getLastSchedule()->getFinishedAt(), Mage_Core_Model_Locale::FORMAT_TYPE_SHORT, true));
            }
       }

        return '<span class="'.$class.'"><span>'.$value.'</span></span>';

    }

}