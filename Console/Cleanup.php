<?php
namespace MageMontreal\ProductGalleryCleanup\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Cleanup extends Command
{
    const OPT_DRY_RUN = 'dry-run';

    const OPT_PROGRESS = 'progress';

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var \Magento\Framework\App\State
     */
    protected $appState;

    /**
     * @var \Magento\Framework\App\Filesystem\DirectoryList
     */
    protected $directoryList;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory
     */
    protected $productCollection;

    /**
     * @var \Magento\Catalog\Model\ProductRepository
     */
    protected $productRepository;

    public function __construct(
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\State $appState,
        \Magento\Framework\App\Filesystem\DirectoryList $directoryList,
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollection,
        \Magento\Catalog\Model\ProductRepository $productRepository
    ){
        parent::__construct();

        $this->storeManager = $storeManager;
        $this->appState = $appState;
        $this->directoryList = $directoryList;
        $this->productCollection = $productCollection;
        $this->productRepository = $productRepository;
    }

   protected function configure()
   {
       $this->setName('catalog:product:gallery:cleanup');
       $this->setDescription('Remove duplicate product images');
       $this->addOption(
           self::OPT_DRY_RUN,
           null,
           InputOption::VALUE_OPTIONAL,
           'Dry Run',
           0
       );
       $this->addOption(
           self::OPT_PROGRESS,
           null,
           InputOption::VALUE_OPTIONAL,
           'Show Progress',
           0
       );
       
       parent::configure();
   }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $isDryRun = $input->getOption(self::OPT_DRY_RUN);
        $showProgress = $input->getOption(self::OPT_PROGRESS);

        $this->appState->setAreaCode('global');
        $this->storeManager->setCurrentStore(0);
        $path = $this->directoryList->getPath('media') .  '/catalog/product';
        $productCollection = $this->productCollection->create();
        $_products = $productCollection
            ->addAttributeToSelect('sku')
            ->addMediaGalleryData();
        $i = 0;
        $total = $_products->getSize();
        $count = 0;

        foreach ($_products as $_product) {
            $_product->setStoreId(0);
            $_md5_values = [];
            $base_image = $_product->getImage();

            if ($base_image !== 'no_selection') {
                $filepath = $path . $base_image;
                if (file_exists($filepath)) {
                    $_md5_values[] = md5(file_get_contents($filepath));
                }
                $i++;

                if ($showProgress) {
                    echo "\r\n processing product $i of $total ";
                }

                $gallery = $_product->getMediaGalleryEntries();
                if ($gallery) {
                    $galleryEdited = false;
                    foreach ($gallery as $key => $galleryImage) {
                       
                        if ($galleryImage->getFile() === $base_image) {
                            continue;
                        }
                        $filepath = $path . $galleryImage->getFile();

                        if (file_exists($filepath)) {
                            $md5 = md5(file_get_contents($filepath));
                        } else {
                            continue;
                        }

                        if (in_array($md5, $_md5_values, true)) {
                            if (count($galleryImage->getTypes()) > 0) {
                                continue;
                            }

                            unset($gallery[$key]);
                            $galleryEdited = true;
                            $count++;

                            if ($showProgress) {
                                echo "\r\n removed duplicate image from " . $_product->getSku();
                            }
                        } else {
                            $_md5_values[] = $md5;
                        }
                    }

                    if (!$isDryRun && $galleryEdited) {
                        $_product->setMediaGalleryEntries($gallery);
                        $this->productRepository->save($_product);
                    }
                }
            }
        }

        $output->writeln("\r\n Removed $count duplicate images.");
    }
}
