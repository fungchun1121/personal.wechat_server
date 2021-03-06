<?php
/**
 * Created by PhpStorm.
 * User: ypoon
 * Date: 10/11/2018
 * Time: 11:21 AM
 */

namespace App\Command;

use App\Entity\Base\SecurityGroup;
use App\Entity\Core\AbstractModule;
use App\Entity\Core\AbstractStoreFront;
use App\Entity\Core\Housing\HousingItem;
use App\Entity\Core\Housing\HousingModule;
use App\Entity\Core\Housing\HousingStoreFront;
use App\Entity\Core\Location;
use App\Entity\Core\SecondHand\SecondHandItem;
use App\Entity\Core\SecondHand\SecondHandModule;
use App\Entity\Core\SecondHand\SecondHandStoreFront;
use App\Entity\Core\StoreItemAsset;
use App\Entity\Core\Ticketing\TicketingItem;
use App\Entity\Core\Ticketing\TicketingModule;
use App\Entity\Core\Ticketing\TicketingStoreFront;
use App\Entity\Core\WeChatUser;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Intervention\Image\Constraint;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Intervention\Image\ImageManagerStatic as Image;

class DemoCommand extends Command {
    private $em;
    private $passwordEncoder;
    private $placeholderPic;
    private $thumbnailWidth;
    private $thumbnailHeight;
    private const DEMO_USER_COUNT = 5;
    private const DEMO_STORE_ITEM_COUNT = 5;

    public function __construct(EntityManagerInterface $em, UserPasswordEncoderInterface $passwordEncoder, ParameterBagInterface $params, $name = null) {
        $this->em = $em;
        $this->passwordEncoder = $passwordEncoder;
        $baseUrl = $params->get("kernel.project_dir");
        $this->thumbnailWidth = $params->get("thumbnail_max_width");
        $this->thumbnailHeight = $params->get("thumbnail_max_height");
        $this->placeholderPic = [
            "secondHand" => [],
            "housing" => []
        ];
        foreach (scandir($baseUrl."/assets/images/demo/housing") as $img) {
            if (preg_match("/^\w.*\.(png|jpg|jpeg)$/", $img)) {
                $path = $baseUrl."/assets/images/demo/housing/".$img;
                $this->placeholderPic["housing"][] = base64_encode(file_get_contents($path));
            }
        }
        foreach (scandir($baseUrl."/assets/images/demo/second_hand_item") as $img) {
            if (preg_match("/^\w.*\.(png|jpg|jpeg)$/", $img)) {
                $path = $baseUrl."/assets/images/demo/second_hand_item/".$img;
                $this->placeholderPic["secondHand"][] = base64_encode(file_get_contents($path));
            }
        }
        parent::__construct($name);
    }

    protected function configure() {
        $this->setName("app:demo")
            ->setDescription("Create demo data for testing");
    }

    public function execute(InputInterface $input, OutputInterface $output) {
        // Base 1
        $users = [];
        for($i = 1; $i <= self::DEMO_USER_COUNT; $i ++) {
            $output->writeln("Creating Demo User user_$i");
            $user = $this->initUser("user_$i");
            $users[] = $user;
        }
        $storeFronts = [];
        foreach ($users as $user) {
            $storeFronts = array_merge($storeFronts, $this->initStoreFront($user));
        }
        $storeItems = [];
        foreach ($storeFronts as $storeFront) {
            $storeItems = array_merge($storeItems, $this->initStoreItem($storeFront));
        }
        $this->em->flush();
    }

    private function initUser(string $username, string $password = "password"): WeChatUser {
        $user = $this->em->getRepository(WeChatUser::class)->findOneBy([
            "username" => $username
        ]);
        $userGroup = $this->em->getRepository(SecurityGroup::class)->findOneBy([
            "siteToken" => "ROLE_USER"
        ]);
        if (!$user) {
            $user = new WeChatUser();
            $user->setUsername($username);
            $user->setFullName($username);
            $user->setWeChatOpenId($username);
            $userGroup->getChildren()->add($user);
        }
        $user->setPlainPassword($password);
        $user->setPassword($this->passwordEncoder->encodePassword($user, $password));

        $this->em->persist($user);
        $this->em->persist($userGroup);
        return $user;
    }

    private function initStoreFront(WeChatUser $user) {
        $city = $this->em->getRepository(Location::class)->findOneBy([
            "name" => "伦敦"
        ]);
        $modules = $city->getModules();
        $storeFronts = [];
        foreach ($modules as $module) {
            switch (get_class($module)) {
                case SecondHandModule::class:
                    /* @var SecondHandModule $module */
                    $storeFront = $module->getStoreFronts()->filter(function(AbstractStoreFront $storeFront) use ($user){
                        return $storeFront->getOwner() === $user;
                    })->first();
                    if (!$storeFront) {
                        $storeFront = new SecondHandStoreFront();
                        $storeFront->setOwner($user);
                        $storeFront->setName($user->getFullName());
                        $storeFront->setModule($module);
                    }
                    break;
                case HousingModule::class:
                    /* @var HousingModule $module */
                    $storeFront = $module->getStoreFronts()->filter(function(AbstractStoreFront $storeFront) use ($user){
                        return $storeFront->getOwner() === $user;
                    })->first();
                    if (!$storeFront) {
                        $storeFront = new HousingStoreFront();
                        $storeFront->setOwner($user);
                        $storeFront->setName($user->getFullName());
                        $storeFront->setModule($module);
                    }
                    break;
                case TicketingModule::class:
                    /* @var TicketingModule $module */
                    $storeFront = $module->getStoreFronts()->filter(function(AbstractStoreFront $storeFront) use ($user){
                        return $storeFront->getOwner() === $user;
                    })->first();
                    if (!$storeFront) {
                        $storeFront = new TicketingStoreFront();
                        $storeFront->setOwner($user);
                        $storeFront->setName($user->getFullName());
                        $storeFront->setModule($module);
                    }
                    break;
                default:
                    throw new \Exception("Unsupported Method");
            }
            $date = new \DateTimeImmutable();
            $offset = rand(1,5);
            $storeFront->setCreateDate($date->modify("-$offset day"));
            $storeFront->setLastTopTime($date->modify("-$offset day"));
            $storeFronts[] = $storeFront;
            $this->em->persist($storeFront);
        }
        return $storeFronts;
    }

    private function initStoreItem(AbstractStoreFront $storeFront) {
        $items = [];
        $max = rand(1, self::DEMO_STORE_ITEM_COUNT);
        switch (get_class($storeFront)) {
            case SecondHandStoreFront::class:
                /* @var SecondHandStoreFront $storeFront */
                for ($i = 1; $i <= $max; $i ++) {
                    $name = "_second_hand_item_$i";
                    $item = $storeFront->getStoreItems()->filter(function(SecondHandItem $item) use ($name) {
                        return $item->getName() === $name;
                    })->first();
                    if (!$item) {
                        $item = new SecondHandItem();
                        $item->setName($name);
                        $item->setStoreFront($storeFront);
                        $item->setDescription(get_class($item));
                        $item->setPrice(rand(1,4) * 100);
                        $item->setVisitorCount(rand(1, 10000));
                        $item->setWeChatId("_we_chat_id");
                        foreach (array_rand($this->placeholderPic["secondHand"], 3) as $index) {
                            $asset = new StoreItemAsset();
                            $asset->setNamespace(SecondHandItem::class);
                            $asset->setMimeType("image/jpeg");
                            $asset->setBase64($this->placeholderPic["secondHand"][$index]);
                            $img = Image::make($asset->getBase64());
                            if ($img->getWidth() > $img->getHeight()) {
                                $img->resize($this->thumbnailWidth, null, function(Constraint $constraint){
                                    $constraint->aspectRatio();
                                    $constraint->upsize();
                                });
                            } else {
                                $img->resize(null, $this->thumbnailHeight, function(Constraint $constraint){
                                    $constraint->aspectRatio();
                                    $constraint->upsize();
                                });
                            }
                            $url = $img->encode("data-url");
                            preg_match("/^data:.+;base64,(.+)/", $url, $match);
                            $asset->setThumbnailBase64($match[1]);
                            $date = new DateTimeImmutable();
                            $offset = rand(1,5);
                            $asset->setCreateDate($date->modify("-$offset day"));
                            $asset->setStoreItem($item);
                            $this->em->persist($asset);
                        }
                        $date = new DateTimeImmutable();
                        $offset = rand(1,5);
                        $item->setCreateDate($date->modify("-$offset day"));
                        $item->setLastTopTime($date->modify("-$offset day"));
                        $this->em->persist($item);
                    }
                    $items[] = $item;
                }
                break;
            case HousingStoreFront::class:
                /* @var HousingStoreFront $storeFront */
                for ($i = 1; $i <= $max; $i ++) {
                    $name = "_housing_item_$i";
                    $item = $storeFront->getStoreItems()->filter(function(HousingItem $item) use ($name) {
                        return $item->getName() === $name;
                    })->first();
                    if (!$item) {
                        $item = new HousingItem();
                        $item->setName($name);
                        $item->setStoreFront($storeFront);
                        $item->setDescription(get_class($item));
                        $item->setPrice(rand(1,4) * 100);
                        $item->setVisitorCount(rand(1, 10000));
                        $item->setLocation("_location");
                        $item->setDuration(rand(10, 730));
                        $item->setPropertyType("_type_1");
                        $item->setWeChatId("_we_chat_id");
                        foreach (array_rand($this->placeholderPic["housing"], 3) as $index) {
                            $asset = new StoreItemAsset();
                            $asset->setNamespace(SecondHandItem::class);
                            $asset->setMimeType("image/jpeg");
                            $asset->setBase64($this->placeholderPic["housing"][$index]);
                            $img = Image::make($asset->getBase64());
                            if ($img->getWidth() > $img->getHeight()) {
                                $img->resize($this->thumbnailWidth, null, function(Constraint $constraint){
                                    $constraint->aspectRatio();
                                    $constraint->upsize();
                                });
                            } else {
                                $img->resize(null, $this->thumbnailHeight, function(Constraint $constraint){
                                    $constraint->aspectRatio();
                                    $constraint->upsize();
                                });
                            }
                            $url = $img->encode("data-url");
                            preg_match("/^data:.+;base64,(.+)/", $url, $match);
                            $asset->setThumbnailBase64($match[1]);
                            $date = new DateTimeImmutable();
                            $offset = rand(1,5);
                            $asset->setCreateDate($date->modify("-$offset day"));
                            $asset->setStoreItem($item);
                            $this->em->persist($asset);
                        }
                        $date = new DateTimeImmutable();
                        $offset = rand(1,5);
                        $item->setCreateDate($date->modify("-$offset day"));
                        $item->setLastTopTime($date->modify("-$offset day"));
                        $this->em->persist($item);
                    }
                    $items[] = $item;
                }
                break;
            case TicketingStoreFront::class:
                /* @var HousingStoreFront $storeFront */
                for ($i = 1; $i <= $max; $i ++) {
                    $name = "_ticketing_item_$i";
                    $item = $storeFront->getStoreItems()->filter(function(TicketingItem $item) use ($name) {
                        return $item->getName() === $name;
                    })->first();
                    if (!$item) {
                        $item = new TicketingItem();
                        $item->setName($name);
                        $item->setStoreFront($storeFront);
                        $item->setDescription(get_class($item));
                        $item->setPrice(rand(1,4) * 100);
                        $item->setVisitorCount(rand(1, 10000));
                        $item->setWeChatId("_we_chat_id");
                        $date = new DateTimeImmutable();
                        $offset = rand(1, 720);
                        $item->setValidTill($date->modify("+$offset day"));
                        $offset = rand(1,5);
                        $item->setCreateDate($date->modify("-$offset day"));
                        $item->setLastTopTime($date->modify("-$offset day"));
                        $this->em->persist($item);
                    }
                    $items[] = $item;
                }
                break;
            default:
                throw new \Exception("Unsupported Method");
        }
        return $items;
    }
}