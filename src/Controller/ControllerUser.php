<?php

namespace User\Controller;

use User\Entity\User;
use User\Entity\Regular;
use User\Entity\ClassroomUser;
use Classroom\Entity\Classroom;
use Classroom\Entity\ClassroomLinkUser;
use Classroom\Entity\ActivityLinkUser;
use Classroom\Entity\ActivityLinkClassroom;
use User\Entity\Teacher;
use Utils\Mailer;
use Exception;



class ControllerUser extends Controller
{
    public $URL = "https://fr.vittascience.com";
    public function __construct($entityManager, $user, $url = "https://fr.vittascience.com")
    {
        $this->URL = $url;
        parent::__construct($entityManager, $user);
        $this->actions = array(
            'get_all' => function () {
                return $this->entityManager->getRepository('User\Entity\User')
                    ->findAll();
            },
            'generate_classroom_user_password' => function ($data) {
                $user = $this->entityManager->getRepository('User\Entity\User')
                    ->find($data['id']);
                $password = passwordGenerator();
                $user->setPassword($password);
                $this->entityManager->persist($user);
                $this->entityManager->flush();
                $pseudo = $user->getPseudo();
                return ['mdp' => $password, 'pseudo' => $pseudo];
            },
            'change_pseudo_classroom_user' => function ($data) {
                $user = $this->entityManager->getRepository('User\Entity\User')
                    ->find($data['id']);
                $user->setPseudo($data['pseudo']);
                $this->entityManager->persist($user);
                $this->entityManager->flush();
                return true;
            },
            'delete' => function ($data) {
                $user = $this->entityManager->getRepository('User\Entity\User')
                    ->find($data['id']);
                $regular = $this->entityManager->getRepository('User\Entity\Regular')
                    ->findOneBy(array('user' => $data['id']));
                /* $teacher = $this->entityManager->getRepository('User\Entity\Teacher')
                    ->findOneBy(array('user' => $data['id'])); */
                $classroomUser = $this->entityManager->getRepository('User\Entity\ClassroomUser')
                    ->findOneBy(array('id' => $data['id']));
                $pseudo = $user->getPseudo();
                $this->entityManager->remove($user);
                if ($regular) {
                    $this->entityManager->remove($regular);
                }
                /* if ($teacher) {
                    $this->entityManager->remove($teacher);
                } */
                if ($classroomUser) {
                    $this->entityManager->remove($classroomUser);
                }
                $this->entityManager->flush();
                return [
                    'pseudo' => $pseudo
                ];
            },
            'get_one_by_pseudo_and_password' => function ($data) {
                $user = $this->entityManager->getRepository('User\Entity\User')
                    ->findBy(array("pseudo" => $data['pseudo']));
                foreach ($user as $u) {
                    if ($data['password'] == $u->getPassword()) {
                        $trueUser = $u;
                        break;
                    }
                }
                if (isset($trueUser)) {
                    $classroom = $this->entityManager->getRepository('Classroom\Entity\Classroom')
                        ->findOneBy(array("link" => $data['classroomLink']));
                    $isThere = $this->entityManager->getRepository('Classroom\Entity\ClassroomLinkUser')
                        ->findOneBy(array("user" => $trueUser, "classroom" => $classroom));
                    if ($isThere) {
                        $_SESSION["id"] = $trueUser->getId();
                        return true;
                    }
                }
                return false;
            },
            'get_one' => function ($data) {
                $regular = $this->entityManager->getRepository('User\Entity\Regular')
                    ->find($data);
                if ($regular) {
                    $teacher = $this->entityManager->getRepository('User\Entity\Teacher')
                        ->find($data);
                    if ($teacher) {
                        return $teacher;
                    } else {
                        return $regular;
                    }
                } else {
                    $classroomUser = $this->entityManager->getRepository('User\Entity\ClassroomUser')
                        ->find($data);
                    return $classroomUser;
                }
            },
            'garSystem' => function () {
                try {
                    $_SESSION['UAI'] = $_GET['uai'];
                    $_SESSION['DIV'] = json_decode(urldecode($_GET['div']));
                    if (isset($_GET['pmel']) && $_GET['pmel'] != '') {
                        $isTeacher = true;
                    } else {
                        $isTeacher = false;
                    } // en fonction des infos sso
                    //check if the user is in the database. If not, create a new User

                    $garUser = $this->entityManager->getRepository('User\Entity\ClassroomUser')
                        ->findBy(array("garId" => $_GET['ido']));
                    if (!$garUser) {
                        $user = new User();
                        $user->setFirstName($_GET['pre']);
                        $user->setSurname($_GET['nom']);
                        $user->setPseudo($_GET['nom'] . " " . $_GET['pre']);
                        $password = passwordGenerator();
                        $user->setPassword(password_hash($password, PASSWORD_DEFAULT));
                        $lastQuestion = $this->entityManager->getRepository('User\Entity\User')->findOneBy([], ['id' => 'desc']);
                        $user->setId($lastQuestion->getId() + 1);
                        $this->entityManager->persist($user);

                        $classroomUser = new ClassroomUser($user);
                        $classroomUser->setGarId($_GET['ido']);
                        $classroomUser->setSchoolId($_GET['uai']);
                        if ($isTeacher) {
                            $classroomUser->setIsTeacher(true);
                            $classroomUser->setMailTeacher($_GET['pmel'] . passwordGenerator());
                            $regular = new Regular($user, $_GET['pmel'] . passwordGenerator());
                            $this->entityManager->persist($regular);

                            /*  $subject = "Votre création de compte Vittascience (test, en prod envoi à l'adresse " . $_GET['pmel'];
                        $body =  "<h4 style=\"font-family:'Open Sans'; margin-bottom:0; color:#27b88e; font-size:28px;\">Bonjour " . $user->getFirstname() . "</h4>";
                        $body .= "<p style=\" font-family:'Open Sans'; \">Vous vous êtes connecté à l'application Vittascience via le GAR.</p>";
                        $body .= "<p style=\" font-family:'Open Sans'; \">Si jamais vous souhaitez vous connecter sans passer par le GAR, voici votre mot de passe provisoire :<bold>" . $password . "</bold>.";

                        Mailer::sendMail("support@vittascience.com", $subject, $body, $body); */
                        } else {
                            $classroomUser->setIsTeacher(false);
                            $classroomUser->setMailTeacher(NULL);

                            $classes = $this->entityManager->getRepository('Classroom\Entity\Classroom')->findBy(array('groupe' => $_SESSION['DIV'], 'school' => $_SESSION['UAI']));
                            foreach ($classes as $c) {
                                $linkToClass = $this->entityManager->getRepository('Classroom\Entity\ClassroomLinkUser')->findBy(array('user' => $user));

                                if (!$linkToClass) {
                                    $linkteacherToGroup = new ClassroomLinkUser($user, $c);
                                    $linkteacherToGroup->setRights(0);
                                    $this->entityManager->persist($linkteacherToGroup);
                                }
                            }
                        }
                        $this->entityManager->persist($classroomUser);
                        $this->entityManager->flush();
                        $_SESSION['id'] = $user->getId();
                        $_SESSION['pin'] = $password;

                        if ($user) {
                            header('location:' . $this->URL . '/classroom/home.php');
                        } else {
                            header('location:' . $this->URL . '/classroom/login.php');
                        }
                    }
                    $classes = $this->entityManager->getRepository('Classroom\Entity\Classroom')->findBy(array('groupe' => $_SESSION['DIV'], 'school' => $_SESSION['UAI']));
                    foreach ($classes as $c) {
                        $linkToClass = $this->entityManager->getRepository('Classroom\Entity\ClassroomLinkUser')->findOneBy(array('user' => $garUser[0]->getId(), 'classroom' => $c));
                        var_dump($linkToClass);
                        if (!$linkToClass || $linkToClass == NULL) {

                            $linkteacherToGroup = new ClassroomLinkUser($garUser[0]->getId(), $c);
                            $linkteacherToGroup->setRights(0);
                            $this->entityManager->persist($linkteacherToGroup);
                        }
                    }
                    $this->entityManager->flush();
                    $_SESSION['id'] = $garUser[0]->getId()->getId();
                    header('location:' . $this->URL . '/classroom/home.php');
                } catch (Exception $e) {
                    var_dump($e);
                }
            },
            'linkSystem' => function ($data) {
                $pseudoUsed = $this->entityManager->getRepository('User\Entity\User')->findBy(array('pseudo' => $data['pseudo']));
                foreach ($pseudoUsed as $p) {
                    $pseudoUsedInClassroom = $this->entityManager->getRepository('Classroom\Entity\ClassroomLinkUser')->findOneBy(array('user' => $p));
                    if ($pseudoUsedInClassroom) {
                        return false;
                    }
                }
                $user = new User();
                $user->setFirstName("élève");
                $user->setSurname("modèl");
                $user->setPseudo($data['pseudo']);
                $password = passwordGenerator();
                $user->setPassword($password);
                $lastQuestion = $this->entityManager->getRepository('User\Entity\User')->findOneBy([], ['id' => 'desc']);
                $user->setId($lastQuestion->getId() + 1);
                $this->entityManager->persist($user);
                $this->entityManager->flush();

                $classroomUser = new ClassroomUser($user);
                $classroomUser->setGarId(null);
                $classroomUser->setSchoolId(null);
                $classroomUser->setIsTeacher(false);
                $classroomUser->setMailTeacher(NULL);
                $this->entityManager->persist($classroomUser);

                $classroom = $this->entityManager->getRepository('Classroom\Entity\Classroom')
                    ->findOneBy(array("link" => $data['classroomLink']));
                $linkteacherToGroup = new ClassroomLinkUser($user, $classroom);
                $linkteacherToGroup->setRights(0);
                $this->entityManager->persist($linkteacherToGroup);

                $activitiesLinkClassroom = $this->entityManager->getRepository('Classroom\Entity\ActivityLinkClassroom')
                    ->findBy(array("classroom" => $classroom));
                //attribute activities linked with the classroom to the learner
                foreach ($activitiesLinkClassroom as $a) {
                    $activityLinkUser = new ActivityLinkUser($a->getActivity(), $user, $a->getDateBegin(),  $a->getDateEnd(), $a->getEvaluation(), $a->getAutocorrection(), $a->getIntroduction());
                    $this->entityManager->persist($activityLinkUser);
                }

                $this->entityManager->flush();
                $user->classroomUser = $classroomUser;
                $user->pin = $password;
                $_SESSION["id"] = $user->getId();
                $_SESSION["pin"] = $password;
                return $user;
            }
        );
    }
}
function passwordGenerator()
{
    $password = '';
    for ($i = 0; $i < 4; $i++) {
        $password .= rand(0, 9);
    }
    return $password;
}
