<?php
namespace Topxia\Service\Sms\SmsProcessor;

use Topxia\Common\CurlToolkit;
use Topxia\Common\ArrayToolkit;
use Topxia\Common\StringToolkit;
use Topxia\Service\Common\ServiceKernel;
use Topxia\Service\CloudPlatform\CloudAPIFactory;
use Symfony\Component\Security\Core\Encoder\MessageDigestPasswordEncoder;

class LiveOpenLessonSmsProcessor extends BaseProcessor implements SmsProcessor
{
    public function getUrls($targetId, $smsType)
    {
        $lesson = $this->getOpenCourseService()->getLesson($targetId);
        $course = $this->getOpenCourseService()->getCourse($lesson['courseId']);
        $count  = 0;

        $count = $this->getOpenCourseService()->searchMemberCount(array('courseId' => $course['id']));

        global $kernel;
        $container          = $kernel->getContainer();
        $siteSetting        = $this->getSettingService()->get('site');
        $siteSetting['url'] = rtrim($siteSetting['url']);
        $siteSetting['url'] = rtrim($siteSetting['url'], '/');
        $hostName           = $siteSetting['url'];
        $api                = CloudAPIFactory::create('root');

        for ($i = 0; $i <= intval($count / 1000); $i++) {
            $urls[$i] = $hostName;
            $urls[$i] .= $container->get('router')->generate('edu_cloud_sms_send_callback', array('targetType' => 'liveOpenLesson', 'targetId' => $targetId));
            $urls[$i] .= '?index='.($i * 1000);
            $urls[$i] .= '&smsType='.$smsType;
            $sign = $this->getSignEncoder()->encodeSign($urls[$i], $api->getAccessKey());
            $sign = rawurlencode($sign);
            $urls[$i] .= '&sign='.$sign;
        }

        return array('count' => $count, 'urls' => $urls);
    }

    public function getSmsInfo($targetId, $index, $smsType)
    {
        global $kernel;
        $siteSetting        = $this->getSettingService()->get('site');
        $siteSetting['url'] = rtrim($siteSetting['url']);
        $siteSetting['url'] = rtrim($siteSetting['url'], '/');
        $hostName           = $siteSetting['url'];
        $lesson             = $this->getOpenCourseService()->getLesson($targetId);

        if (empty($lesson)) {
            throw new \RuntimeException('课时不存在');
        }

        $originUrl = $hostName;
        $originUrl .= $kernel->getContainer()->get('router')->generate('open_course_show', array('courseId' => $lesson['courseId']));

        $url    = $this->changeLink($originUrl);
        $course = $this->getOpenCourseService()->getCourse($lesson['courseId']);
        $to     = '';

        $students = $this->getOpenCourseService()->searchMembers(array('courseId' => $course['id']), array('createdTime', 'Desc'), $index, 1000);

        $to = array_filter(ArrayToolkit::column($students, 'mobile'));
        $to = implode(',', $to);

        $parameters['lesson_title'] = '';

        if ($lesson['type'] == 'liveOpen') {
            $parameters['startTime'] = date("Y-m-d H:i:s", $lesson['startTime']);
        }

        $course['title']            = StringToolkit::cutter($course['title'], 20, 15, 4);
        $parameters['course_title'] = '直播公开课：《'.$course['title'].'》';

        $description = $parameters['course_title'].' '.$parameters['lesson_title'].'预告';

        $parameters['url'] = $url;

        return array('mobile' => $to, 'category' => $smsType, 'description' => $description, 'parameters' => $parameters);
    }

    private function changeLink($url)
    {
        $arrResponse = CurlToolkit::request('POST', "http://dwz.cn/create.php", array('url' => $url));

        if ($arrResponse['status'] != 0) {
            $qqArrResponse = CurlToolkit::request('POST', "http://qqurl.com/create/", array('url' => $url));

            if ($qqArrResponse['status'] != 0) {
                return $url.' ';
            } else {
                return $qqArrResponse['short_url'].' ';
            }
        } else {
            return $arrResponse['tinyurl'].' ';
        }
    }

    protected function getUserService()
    {
        return ServiceKernel::instance()->createService('User.UserService');
    }

    protected function getOpenCourseService()
    {
        return ServiceKernel::instance()->createService('OpenCourse.OpenCourseService');
    }

    protected function getSettingService()
    {
        return ServiceKernel::instance()->createService('System.SettingService');
    }

    protected function getSignEncoder()
    {
        return new MessageDigestPasswordEncoder('sha256');
    }
}
