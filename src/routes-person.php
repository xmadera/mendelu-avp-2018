<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

/**
 * VYPIS PERSONS
 */

$app->get('//persons[/{page:[0-9]+}]', function (Request $request, Response $response, $args) {
    $q = $request->getQueryParam('q');
    $pageLimit = 10;
    try {
        if (empty($q)) {
            $page = !empty($args['page']) ? $args['page'] : 0;
            $stmt = $this->db->prepare('SELECT person.*, location.*, pocet_k, pocet_s
                FROM person
                LEFT JOIN location USING (id_location)
                LEFT JOIN (
                  SELECT id_person, COUNT(*) AS pocet_k
                  FROM contact
                  GROUP BY id_person
                ) AS pocty_kontaktu USING (id_person)
                LEFT JOIN (
                  SELECT id_person, COUNT(*) AS pocet_s
                  FROM person_meeting
                  GROUP BY id_person
                ) AS pocty_schuzek USING (id_person)   
                ORDER BY last_name
                LIMIT :pl OFFSET :of');
            $stmt->bindValue(':pl', $pageLimit);
            $stmt->bindValue(':of', $page * $pageLimit);
            $stmt->execute();
            $stmtCnt = $this->db->query('SELECT COUNT(*) AS cnt FROM person');
            $pageInfo = $stmtCnt->fetch();
            $tplVars['pCount'] = ceil($pageInfo['cnt'] / $pageLimit);
            $tplVars['pLimit'] = $pageLimit;
            $tplVars['pCurr'] = $page;
            $tplVars['persons'] = $stmt->fetchAll();
        } else {
            $stmt = $this->db->prepare('SELECT person.*, location.*, pocet_k, pocet_s
                FROM person
                LEFT JOIN location USING (id_location)
                LEFT JOIN (
                  SELECT id_person, COUNT(*) AS pocet_k
                  FROM contact
                  GROUP BY id_person
                ) AS pocty_kontaktu USING (id_person)
                LEFT JOIN (
                  SELECT id_person, COUNT(*) AS pocet_s
                  FROM person_meeting
                  GROUP BY id_person
                ) AS pocty_schuzek USING (id_person)
                WHERE last_name ILIKE :q OR
                first_name ILIKE :q OR 
                nickname ILIKE :q
                ORDER BY last_name');
            $stmt->bindValue(':q', $q . '%');
        }
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }
    $tplVars['people'] = $stmt->fetchAll();
    return $this->view->render($response, 'persons.latte', $tplVars);
})->setName('persons');

/**
 * SMAZANI PERSON
 */

$app->post('/delete-person', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    try {
        $stmt = $this->db->prepare("DELETE FROM person
                                    WHERE id_person = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }
    return $response->withHeader('Location',
        $this->router->pathFor('persons'));
})->setName('deletePerson');

/**
 * EDITACE PERSON
 */
$app->get('/edit-person', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    try {
        $stmt = $this->db->prepare("SELECT person.*, location.*
                                    FROM person
                                    LEFT JOIN location USING (id_location)
                                    WHERE person.id_person = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }
    $person = $stmt->fetch(); //NE fetchAll()
    if (empty($person)) {
        die('Osoba nenalezena.');
    }
    $tplVars['id'] = $id;
    $tplVars['form'] = [
        'fn' => $person['first_name'],
        'ln' => $person['last_name'],
        'nn' => $person['nickname'],
        'h' => $person['height'],
        'g' => $person['gender'],
        'bd' => $person['birth_day'],
        'ci' => $person['city'],
        'st' => $person['street_name'],
        'sn' => $person['street_number'],
        'zip' => $person['zip']

    ];
    return $this->view->render($response, 'edit-person.latte', $tplVars);
})->setName('editPerson');

$app->post('/edit-person', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    $data = $request->getParsedBody();
    $idLocation = null;
    if (!empty($data['fn']) && !empty($data['ln']) && !empty($data['nn'])) {


            $stmt = $this->db->prepare('UPDATE location AS l
                  SET city = :ci, street_name = :st, street_number = :sn, zip = :zip
                  FROM person AS p
                  WHERE p.id_person = :id AND p.id_location = l.id_location');
            $stmt->bindValue(':id', $id);
            $stmt->bindValue(':ci', $data['ci']);
            $stmt->bindValue(':st', empty($data['st']) ? null : $data['st']);
            $stmt->bindValue(':sn', empty($data['sn']) ? null : $data['sn']);
            $stmt->bindValue(':zip', empty($data['zip']) ? null : $data['zip']);
            $stmt->execute();

        try {
            $stmt = $this->db->prepare('UPDATE person SET
                first_name = :fn, last_name = :ln, nickname = :nn,
                gender = :g, height = :h, birth_day = :bd
              WHERE id_person = :id');
            $h = empty($data['h']) ? null : $data['h'];
            $g = empty($data['g']) ? null : $data['g'];
            $bd = empty($data['bd']) ? null : $data['bd'];
            $stmt->bindValue(':id', $id);
            $stmt->bindValue(':fn', $data['fn']);
            $stmt->bindValue(':ln', $data['ln']);
            $stmt->bindValue(':nn', $data['nn']);
            $stmt->bindValue(':g', $g);
            $stmt->bindValue(':h', $h);
            $stmt->bindValue(':bd', $bd);
            $stmt->execute();


        } catch (Exception $e) {
                $this->logger->error($e->getMessage());
                die($e->getMessage());
            }
        return $response->withHeader('Location', $this->router->pathFor('persons'));
    } else {
        $tplVars['error'] = 'Vyplnte povinne udaje.';
        $tplVars['form'] = $data;
        return $this->view->render($response, 'edit-person.latte', $tplVars);
    }
});

/**
 * NOVY PERSON
 */

$app->get('/new-with-address', function (Request $request, Response $response, $args) {
    $tplVars['form'] = [
        'fn' => '', 'ln' => '', 'nn' => '', 'h' => 180,
        'g' => '', 'bd' => '', 'ci' => '', 'st' => '',
        'sn' => '', 'zip' => '', 'idl' => ''
    ];

    try {
        $stmt = $this->db->query('SELECT * FROM location
                                  WHERE city IS NOT NULL AND street_name IS NOT NULL
                                  ORDER BY city, street_name');
        $tplVars['locations'] = $stmt->fetchAll();
    } catch(Exception $ex) {
        $this->logger->error($ex->getMessage());
        die($ex->getMessage());
    }

    return $this->view->render($response, 'new-with-address.latte', $tplVars);
})->setName('newWithAddress');

$app->post('/new-with-address', function (Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    if (!empty($data['fn']) && !empty($data['ln']) && !empty($data['nn']))
    {
        try {
            $this->db->beginTransaction();

            $idLocation = null;
            if(!empty($data['ci'])) {
                $stmt = $this->db->prepare('INSERT INTO location
                  (city, street_name, street_number, zip)
                  VALUES
                  (:ci, :st, :sn, :zip)');
                $stmt->bindValue(':ci', $data['ci']);
                $stmt->bindValue(':st', empty($data['st']) ? null : $data['st']);
                $stmt->bindValue(':sn', empty($data['sn']) ? null : $data['sn']);
                $stmt->bindValue(':zip', empty($data['zip']) ? null : $data['zip']);
                $stmt->execute();
                $idLocation = $this->db->lastInsertId('location_id_location_seq');
            } else if(!empty($data['idl'])) {
                $idLocation = $data['idl'];
            }

            $stmt = $this->db->prepare('INSERT INTO person
                (id_location, birth_day, gender, first_name, last_name, nickname, height)
                VALUES
                (:idl, :bd, :g, :fn, :ln, :nn, :h)');

            $stmt->bindValue(':idl', $idLocation);

            $h = empty($data['h']) ? null : $data['h'];
            $g = empty($data['g']) ? null : $data['g'];
            $bd = empty($data['bd']) ? null : $data['bd'];

            $stmt->bindValue(':fn', $data['fn']);
            $stmt->bindValue(':ln', $data['ln']);
            $stmt->bindValue(':nn', $data['nn']);
            $stmt->bindValue(':g', $g);
            $stmt->bindValue(':h', $h);
            $stmt->bindValue(':bd', $bd);
            $stmt->execute();

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            if ($e->getCode() == 23505) {
                $tplVars['error'] = 'Tato osoba uz existuje.';
                $tplVars['form'] = $data;
                return $this->view->render($response, 'new-with-address.latte', $tplVars);
            } else {
                $this->logger->error($e->getMessage());
                die($e->getMessage());
            }
        }
        return $response->withHeader('Location', $this->router->pathFor('persons'));
    } else {
        $tplVars['error'] = 'Vyplnte povinne udaje.';
        $tplVars['form'] = $data;
        return $this->view->render($response, 'new-with-address.latte', $tplVars);
    }
});

/**
 * SMAZANI LOCATION
 */

$app->post('/delete-location', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    try {
        $stmt = $this->db->prepare("DELETE FROM location
                                    WHERE id_location = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        if ($e->getCode() == 23503) {
            $tplVars['error'] = 'Tato lokace je pouzivana.';
            return $this->view->render($response, 'basic-edit.latte', $tplVars);
        } else {
            $this->logger->error($e->getMessage());
            die($e->getMessage());
        }
    }
    return $response->withHeader('Location',
        $this->router->pathFor('persons'));
})->setName('deleteLocation');


/**
 * INFO O PERSON
 */

$app->get('/info-person', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    try {

        $stmt = $this->db->prepare("SELECT person.*, location.*
                                    FROM person
                                    LEFT JOIN location 
                                      ON location.id_location = person.id_location
                                    WHERE person.id_person = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }
    $person = $stmt->fetch();

    $tplVars['person'] = [
        'fn' => $person['first_name'],
        'ln' => $person['last_name'],
        'nn' => $person['nickname'],
        'h' => $person['height'],
        'g' => $person['gender'],
        'bd' => $person['birth_day'],
        'c' => $person['country'],
        'ct' => $person['city'],
        'sna' => $person['street_name'],
        'snu' => $person['street_number'],
        'zip' => $person['zip'],
        'idp' => $person['id_person'],
        'idl' => $person['id_location']
    ];

    try {

        $stmt = $this->db->prepare("SELECT person.*, meeting.*, person_meeting.*, location.*
                                    FROM person
                                    LEFT JOIN person_meeting
                                      ON person_meeting.id_person = person.id_person
                                    LEFT JOIN meeting 
                                      ON meeting.id_meeting = person_meeting.id_meeting
                                      LEFT JOIN location
                                      ON location.id_location = meeting.id_location
                                    WHERE person.id_person = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }

    $tplVars['meeting'] = $stmt->fetchAll();

    try {

        $stmt = $this->db->prepare("SELECT person.*, contact.*, contact_type.*
                                    FROM person
                                    LEFT JOIN contact
                                      ON contact.id_person = person.id_person
                                    LEFT JOIN contact_type 
                                      ON contact_type.id_contact_type = contact.id_contact_type
                                    WHERE person.id_person = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }


    $tplVars['contact'] = $stmt->fetchAll();

    try {

        $stmt = $this->db->prepare("SELECT person.*, relation.*, relation_type.*
                                    FROM person
                                    LEFT JOIN relation
                                      ON relation.id_person1 = person.id_person OR relation.id_person2 = person.id_person
                                    LEFT JOIN relation_type
                                      ON relation_type.id_relation_type = relation.id_relation_type
                                    WHERE (relation.id_person1 = :id OR relation.id_person2 = :id) AND person.id_person <> :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }


    $tplVars['relation'] = $stmt->fetchAll();

    return $this->view->render($response, 'person.latte', $tplVars);
})->setName('infAboutPerson');

/**
 * VYPSANI CONTACTS
 */

$app->get('/show-contacts', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');

    try {

        $stmt = $this->db->prepare("SELECT person.*, contact.*, contact_type.*
                                    FROM person
                                    LEFT JOIN contact
                                      ON contact.id_person = person.id_person
                                    LEFT JOIN contact_type 
                                      ON contact_type.id_contact_type = contact.id_contact_type
                                    WHERE person.id_person = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }

    $tplVars['id'] = $id;
    $tplVars['contact'] = $stmt->fetchAll();


    try {
        $stmt = $this->db->query('SELECT * FROM contact_type
                                  ORDER BY name');
        $tplVars['contact_type'] = $stmt->fetchAll();
    } catch(Exception $ex) {
        $this->logger->error($ex->getMessage());
        die($ex->getMessage());
    }

    return $this->view->render($response, 'edit-contacts.latte', $tplVars);
})->setName('showContacts');


/**
 * EDIT CONTACTS
 */

$app->post('/edit-contacts', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    $data = $request->getParsedBody();
    if (!empty($data['idc']) && !empty($data['c']))
    {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare('INSERT INTO contact
                  (id_contact_type, contact, id_person)
                  VALUES
                  (:idc, :c, :id)');
            $stmt->bindValue(':idc', $data['idc']);
            $stmt->bindValue(':c', $data['c']);
            $stmt->bindValue(':id', $id);
            $stmt->execute();

            $this->db->commit();

        } catch (Exception $e) {
            $this->db->rollback();
            if ($e->getCode() == 23505) {
                $tplVars['error'] = 'Tento kontakt uz existuje.';
                return $this->view->render($response, 'edit-contacts.latte', $tplVars);
            } else {
                $this->logger->error($e->getMessage());
                die($e->getMessage());
            }
        }
        return $response->withHeader('Location', $this->router->pathFor('persons'));
    } else {
        $tplVars['error'] = 'Vyplnte povinne udaje.';
        return $this->view->render($response, 'edit-contacts.latte', $tplVars);
    }
})->setName('editContacts');


/**
 * SMAZANI CONTACT
 */

$app->post('/delete-contact', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    try {
        $stmt = $this->db->prepare("DELETE FROM contact
                                    WHERE id_contact = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }
    return $response->withHeader('Location',
        $this->router->pathFor('persons'));
})->setName('deleteContact');

$app->get('/show-relations', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');

    try {

        $stmt = $this->db->prepare("SELECT person.*, relation.*, relation_type.*
                                    FROM person
                                    LEFT JOIN relation
                                      ON relation.id_person1 = person.id_person OR relation.id_person2 = person.id_person
                                    LEFT JOIN relation_type 
                                      ON relation_type.id_relation_type = relation.id_relation_type
                                    WHERE (relation.id_person1 = :id OR relation.id_person2 = :id) AND person.id_person <> :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }

    $tplVars['id'] = $id;
    $tplVars['relation'] = $stmt->fetchAll();

    try {
        $stmt = $this->db->query('SELECT * FROM person
                                  ORDER BY first_name');
        $tplVars['persons'] = $stmt->fetchAll();
    } catch(Exception $ex) {
        $this->logger->error($ex->getMessage());
        die($ex->getMessage());
    }

    try {
        $stmt = $this->db->query('SELECT * FROM relation_type
                                  ORDER BY name');
        $tplVars['relation_type'] = $stmt->fetchAll();
    } catch(Exception $ex) {
        $this->logger->error($ex->getMessage());
        die($ex->getMessage());
    }


    return $this->view->render($response, 'edit-relations.latte', $tplVars);
})->setName('showRelations');


/**
 * EDIT RELATIONS
 */

$app->post('/edit-relations', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    $data = $request->getParsedBody();
    if (!empty($data['idr']) && !empty($data['idp']))
    {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare('INSERT INTO relation
                  (id_relation_type, description, id_person1, id_person2)
                  VALUES
                  (:idr, :d, :id, :idp)');
            $stmt->bindValue(':idr', $data['idr']);
            $stmt->bindValue(':d', $data['d']);
            $stmt->bindValue(':id', $id);
            $stmt->bindValue(':idp',$data['idp']);
            $stmt->execute();

            $this->db->commit();

        } catch (Exception $e) {
            $this->db->rollback();
            if ($e->getCode() == 23505) {
                $tplVars['error'] = 'Tento vztah uz existuje.';
                return $this->view->render($response, 'edit-relations.latte', $tplVars);
            } else {
                $this->logger->error($e->getMessage());
                die($e->getMessage());
            }
        }
        return $response->withHeader('Location', $this->router->pathFor('persons'));
    } else {
        $tplVars['error'] = 'Vyplnte povinne udaje.';
        return $this->view->render($response, 'edit-relations.latte', $tplVars);
    }
})->setName('editRelations');

/**
 * SMAZANI RELATION
 */

$app->post('/delete-relation', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    try {
        $stmt = $this->db->prepare("DELETE FROM relation
                                    WHERE id_relation = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }
    return $response->withHeader('Location',
        $this->router->pathFor('persons'));
})->setName('deleteRelation');


/**
 * VYPSANI MEETINGS
 */


$app->get('/show-meetings', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');

    try {

        $stmt = $this->db->prepare("SELECT person.*, meeting.*, person_meeting.*, location.*
                                    FROM person
                                    LEFT JOIN person_meeting
                                      ON person_meeting.id_person = person.id_person
                                    LEFT JOIN meeting 
                                      ON meeting.id_meeting = person_meeting.id_meeting
                                        LEFT JOIN location
                                      ON location.id_location = meeting.id_location
                                    WHERE person.id_person = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }

    $tplVars['id'] = $id;
    $tplVars['meeting'] = $stmt->fetchAll();


    try {
        $stmt = $this->db->query('SELECT * FROM location
                                  WHERE city IS NOT NULL AND street_name IS NOT NULL
                                  ORDER BY city, street_name');
        $tplVars['locations'] = $stmt->fetchAll();
    } catch(Exception $ex) {
        $this->logger->error($ex->getMessage());
        die($ex->getMessage());
    }

    try {
        $stmt = $this->db->query('SELECT * FROM meeting
                                  ORDER BY description');
        $tplVars['meeting_sel'] = $stmt->fetchAll();
    } catch(Exception $ex) {
        $this->logger->error($ex->getMessage());
        die($ex->getMessage());
    }


    return $this->view->render($response, 'edit-meetings.latte', $tplVars);
})->setName('showMeetings');



/**
 * EDIT MEETINGS
 */

$app->post('/edit-meetings', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    $data = $request->getParsedBody();
    $idMeeting = null;
    $idLocation = null;
    if (!empty($data['s']))
    {
        try {
            $this->db->beginTransaction();

            if(!empty($data['ci'])) {
                $stmt = $this->db->prepare('INSERT INTO location
                  (city, street_name, street_number, zip)
                  VALUES
                  (:ci, :st, :sn, :zip)');
                $stmt->bindValue(':ci', $data['ci']);
                $stmt->bindValue(':st', empty($data['st']) ? null : $data['st']);
                $stmt->bindValue(':sn', empty($data['sn']) ? null : $data['sn']);
                $stmt->bindValue(':zip', empty($data['zip']) ? null : $data['zip']);
                $stmt->execute();
                $idLocation = $this->db->lastInsertId('location_id_location_seq');
            } else if(!empty($data['idl'])) {
                $idLocation = $data['idl'];
            }

            $stmt = $this->db->prepare('INSERT INTO meeting
                  (start, description, duration, id_location)
                  VALUES
                  (:s, :d, :du, :idl)');
            $stmt->bindValue(':s', $data['s']);
            $stmt->bindValue(':d', $data['d']);
            $stmt->bindValue(':du',  empty($data['du']) ? null : $data['du']);
            $stmt->bindValue(':idl', $idLocation);
            $stmt->execute();
            $idMeeting = $this->db->lastInsertId('meeting_id_meeting_seq');

            $stmt = $this->db->prepare('INSERT INTO person_meeting
                  (id_person, id_meeting)
                  VALUES
                  (:id, :idm)');
            $stmt->bindValue(':id', $id);
            $stmt->bindValue(':idm', $idMeeting);
            $stmt->execute();

            $this->db->commit();

        } catch (Exception $e) {
            $this->db->rollback();
            if ($e->getCode() == 23505) {
                $tplVars['error'] = 'Toto setkání uz existuje.';
                return $this->view->render($response, 'edit-meetings.latte', $tplVars);
            } else {
                $this->logger->error($e->getMessage());
                die($e->getMessage());
            }
        }
        return $response->withHeader('Location', $this->router->pathFor('persons'));
    } else {
        $tplVars['error'] = 'Vyplnte povinne udaje.';
        return $this->view->render($response, 'edit-meetings.latte', $tplVars);
    }
})->setName('editMeetings');


/**
 * PERSON PRIPOJENI K MEETING
 */

$app->post('/join-meeting', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    $data = $request->getParsedBody();
    if (!empty($data['idm']))
    {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare('INSERT INTO person_meeting
                  (id_person, id_meeting)
                  VALUES
                  (:id, :idm)');
            $stmt->bindValue(':id', $id);
            $stmt->bindValue(':idm',$data['idm']);
            $stmt->execute();

            $this->db->commit();

        } catch (Exception $e) {
            $this->db->rollback();
        }
        return $response->withHeader('Location', $this->router->pathFor('persons'));
    } else {
        $tplVars['error'] = 'Vyplnte povinne udaje.';
        return $this->view->render($response, 'edit-meetings.latte', $tplVars);
    }
})->setName('joinMeeting');


/**
 * SMAZANI PERSON Z PERSON_MEETING
 */

$app->post('/delete-from-meeting', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    try {
        $stmt = $this->db->prepare("DELETE FROM person_meeting
                                    WHERE id_person = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }
    return $response->withHeader('Location',
        $this->router->pathFor('persons'));
})->setName('deleteFromMeeting');


/**
 * ZAKLADNI EDIT, TYPY
 */

$app->get('/show-basic-edit', function (Request $request, Response $response, $args) {

    try {
        $stmt = $this->db->query('SELECT * FROM location
                                  ORDER BY country');
        $tplVars['locations'] = $stmt->fetchAll();
    } catch(Exception $ex) {
        $this->logger->error($ex->getMessage());
        die($ex->getMessage());
    }

    try {
        $stmt = $this->db->query('SELECT * FROM contact_type
                                  ORDER BY name');
        $tplVars['contact_types'] = $stmt->fetchAll();
    } catch(Exception $ex) {
        $this->logger->error($ex->getMessage());
        die($ex->getMessage());
    }

    try {
        $stmt = $this->db->query('SELECT * FROM relation_type
                                  ORDER BY name');
        $tplVars['relation_types'] = $stmt->fetchAll();
    } catch(Exception $ex) {
        $this->logger->error($ex->getMessage());
        die($ex->getMessage());
    }

    try {
        $stmt = $this->db->query('SELECT * FROM meeting
                                  ORDER BY start');
        $tplVars['meetings'] = $stmt->fetchAll();
    } catch(Exception $ex) {
        $this->logger->error($ex->getMessage());
        die($ex->getMessage());
    }


    return $this->view->render($response, 'basic-edit.latte', $tplVars);
})->setName('showBasicEdit');


/**
 * NOVY CONTACT_TYPE
 */

$app->post('/new-contact-type', function (Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    if (!empty($data['n']))
    {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare('INSERT INTO contact_type
                  (name, validation_regexp)
                  VALUES
                  (:n, :n)');
            $stmt->bindValue(':n', $data['n']);
            $stmt->bindValue(':n',$data['n']);
            $stmt->execute();

            $this->db->commit();

        } catch (Exception $e) {
            $this->db->rollback();
            if ($e->getCode() == 23505) {
                $tplVars['error'] = 'Tento typ už existuje.';
                return $this->view->render($response, 'basic-edit.latte', $tplVars);
            } else {
                $this->logger->error($e->getMessage());
                die($e->getMessage());
            }
        }
        return $response->withHeader('Location', $this->router->pathFor('persons'));
    } else {
        $tplVars['error'] = 'Vyplnte povinne udaje.';
        return $this->view->render($response, 'basic-edit.latte', $tplVars);
    }
})->setName('newContactType');

/**
 * SMAZANI CONTACT_TYPE
 */

$app->post('/delete-contact-type', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    try {
        $stmt = $this->db->prepare("DELETE FROM contact_type
                                    WHERE id_contact_type = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        if ($e->getCode() == 23503) {
            $tplVars['error'] = 'Tento typ je pouzivany.';
            return $this->view->render($response, 'basic-edit.latte', $tplVars);
        } else {
            $this->logger->error($e->getMessage());
            die($e->getMessage());
        }
    }
    return $response->withHeader('Location',
        $this->router->pathFor('persons'));
})->setName('deleteContactType');

/**
 * SMAZANI RELATION_TYPE
 */

$app->post('/delete-relation-type', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    try {
        $stmt = $this->db->prepare("DELETE FROM relation_type
                                    WHERE id_relation_type = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        if ($e->getCode() == 23503) {
            $tplVars['error'] = 'Tento typ je pouzivany.';
            return $this->view->render($response, 'basic-edit.latte', $tplVars);
        } else {
            $this->logger->error($e->getMessage());
            die($e->getMessage());
        }
    }
    return $response->withHeader('Location',
        $this->router->pathFor('persons'));
})->setName('deleteRelationType');


/**
 * NOVY RELATION_TYPE
 */

$app->post('/new-relation-type', function (Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    if (!empty($data['n']))
    {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare('INSERT INTO relation_type
                  (name)
                  VALUES
                  (:n, :n)');
            $stmt->bindValue(':n', $data['n']);
            $stmt->execute();

            $this->db->commit();

        } catch (Exception $e) {
            $this->db->rollback();
            if ($e->getCode() == 23505) {
                $tplVars['error'] = 'Tento typ už existuje.';
                return $this->view->render($response, 'basic-edit.latte', $tplVars);
            } else {
                $this->logger->error($e->getMessage());
                die($e->getMessage());
            }
        }
        return $response->withHeader('Location', $this->router->pathFor('persons'));
    } else {
        $tplVars['error'] = 'Vyplnte povinne udaje.';
        return $this->view->render($response, 'basic-edit.latte', $tplVars);
    }
})->setName('newRelationType');

/**
 * SMAZANI MEETING
 */

$app->post('/delete-meeting', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    try {

        $stmt = $this->db->prepare("DELETE FROM meeting
                                    WHERE id_meeting = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();

    } catch (Exception $e) {
        if ($e->getCode() == 23503) {
            $tplVars['error'] = 'Na tomto setkani nekdo byl.';
            return $this->view->render($response, 'basic-edit.latte', $tplVars);
        } else {
            $this->logger->error($e->getMessage());
            die($e->getMessage());
        }
    }
    return $response->withHeader('Location',
        $this->router->pathFor('persons'));
})->setName('deleteMeeting');








