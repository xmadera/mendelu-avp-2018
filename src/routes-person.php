<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

$app->get('/', function (Request $request, Response $response, $args) {
    $q = $request->getQueryParam('q');
    try {
        if (empty($q)) {
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
                ORDER BY last_name');
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
})->setName('deletePerson'); // {link deletePerson} -> /~login/.../delete-person

/**
 * zobrazit form
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

/**
 * zpracovat data, aktualizovat osobu
 */
$app->post('/edit-person', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    $data = $request->getParsedBody();
    if (!empty($data['fn']) && !empty($data['ln']) && !empty($data['nn'])) {
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

        } catch (Exception $e) {
            if ($e->getCode() == 23505) {
                $tplVars['error'] = 'Tato osoba uz existuje.';
                $tplVars['form'] = $data;
                return $this->view->render($response, 'edit-person.latte', $tplVars);
            } else {
                $this->logger->error($e->getMessage());
                die($e->getMessage());
            }
        }
        return $response->withHeader('Location', $this->router->pathFor('persons'));
    } else {
        $tplVars['error'] = 'Vyplnte povinne udaje.';
        $tplVars['form'] = $data;
        return $this->view->render($response, 'edit-person.latte', $tplVars);
    }
});

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
    try {
        $stmt = $this->db->query('SELECT * FROM location
                                  WHERE city IS NOT NULL AND street_name IS NOT NULL
                                  ORDER BY city, street_name');
        $tplVars['locations'] = $stmt->fetchAll();
    } catch(Exception $ex) {
        $this->logger->error($ex->getMessage());
        die($ex->getMessage());
    }

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

        $stmt = $this->db->prepare("SELECT person.*, meeting.*, person_meeting.*
                                    FROM person
                                    LEFT JOIN person_meeting
                                      ON person_meeting.id_person = person.id_person
                                    LEFT JOIN meeting 
                                      ON meeting.id_meeting = person_meeting.id_meeting
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
                                    WHERE person.id_person = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }


    $tplVars['relation'] = $stmt->fetchAll();

    return $this->view->render($response, 'person.latte', $tplVars);
})->setName('infAboutPerson');














