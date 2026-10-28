<?php
/* ActiveSync Tasks mapped to native Stalwart JSCalendar Tasks. */
trait StalwartTasks {
    private function TaskData($task) {
        $data = json_decode($task->data_json, true, 512, JSON_THROW_ON_ERROR);
        if (($data['@type'] ?? null) !== 'Task') throw new RuntimeException('Calendar item is not a Task');
        return $data;
    }

    private function TaskStat($task) {
        $data = $this->TaskData($task);
        // Versions through 0.2.3 sent an empty DateCompleted for these tasks.
        // Change only their fingerprint once so the corrected wire data is
        // exported even when the native object has not changed since then.
        $revision = (($data['progress'] ?? '') === 'completed' && $this->TaskCompletionTimestamp($data) === 0)
            ? "\0task-completed-epoch-v1" : '';
        return array('id' => $task->id, 'flags' => 1, 'mod' => hash('sha256', $task->data_json . $revision));
    }

    private function TaskCompletionTimestamp($data) {
        foreach (array('progressUpdated', 'updated', 'created') as $property) {
            $timestamp = $this->TaskTimestamp($data[$property] ?? null);
            if ($timestamp !== null) return $timestamp;
        }
        return 0;
    }

    private function ReadTask($folderid, $id) {
        $folder = $this->_folders[$this->GetFolderIndex($folderid)];
        foreach ($this->_jmapClient->getTasks(array($id)) as $task) {
            $data = $this->TaskData($task);
            if ($task->id === $id && !empty($data['calendarIds'][$folder->jmapId])) return $task;
        }
        throw new RuntimeException('Task not found in this folder');
    }

    private function GetTaskMessageList($index) {
        $calendar = $this->_folders[$index]->jmapId;
        // Tasks without dates, overdue tasks and completed tasks must remain visible.
        $ids = $this->QueryCalendarEventIds($calendar, null);
        if (count($ids) >= self::QUERY_MAX_IDS) throw new RuntimeException('Task inventory exceeds query limit');
        $messages = array();
        foreach (array_chunk($ids, 500) as $batch) {
            foreach ($this->_jmapClient->getTasks($batch) as $task) {
                $data = $this->TaskData($task);
                if (!empty($data['calendarIds'][$calendar])) $messages[] = $this->TaskStat($task);
            }
        }
        return $messages;
    }

    private function TaskTimestamp($value, $zone = null) {
        if ($value === null || $value === '') return null;
        return (new DateTimeImmutable($value, new DateTimeZone($zone ?: 'UTC')))->getTimestamp();
    }

    private function DeleteTaskMessage($folderid, $id) {
        $data = $this->TaskData($this->ReadTask($folderid, $id));
        if (count(array_filter($data['calendarIds'])) > 1) {
            $calendar = $this->_folders[$this->GetFolderIndex($folderid)]->jmapId;
            $path = 'calendarIds/' . str_replace(array('~', '/'), array('~0', '~1'), $calendar);
            return $this->_jmapClient->updateCalendarEvent($id, json_encode(array($path => null), JSON_THROW_ON_ERROR));
        }
        return $this->_jmapClient->destroyCalendarEvent($id);
    }

    private function TaskReminder($data) {
        $times = array();
        foreach (($data['alerts'] ?? array()) as $alert) {
            if (($alert['action'] ?? 'display') !== 'display') continue;
            $trigger = $alert['trigger'] ?? array();
            if (($trigger['@type'] ?? '') === 'AbsoluteTrigger') {
                $time = $this->TaskTimestamp($trigger['when'] ?? null);
            } elseif (($trigger['@type'] ?? '') === 'OffsetTrigger') {
                $base = (($trigger['relativeTo'] ?? 'start') === 'end') ? ($data['due'] ?? null) : ($data['start'] ?? null);
                $time = $this->TaskTimestamp($base, $data['timeZone'] ?? null);
                if ($time !== null) {
                    $offset = $trigger['offset'];
                    $interval = new DateInterval(ltrim($offset, '+-'));
                    $date = (new DateTimeImmutable('@' . $time));
                    $time = (($offset[0] ?? '') === '-' ? $date->sub($interval) : $date->add($interval))->getTimestamp();
                }
            } else continue;
            if ($time !== null && (!isset($alert['acknowledged']) || $this->TaskTimestamp($alert['acknowledged']) < $time)) $times[] = $time;
        }
        return empty($times) ? null : min($times);
    }

    private function GetTaskMessage($folderid, $id, $contentparameters) {
        $data = $this->TaskData($this->ReadTask($folderid, $id));
        $output = new SyncTask();
        $output->subject = $data['title'] ?? '';
        $output->complete = (($data['progress'] ?? '') === 'completed') ? 1 : 0;
        if ($output->complete) {
            // ActiveSync requires DateCompleted. Prefer the native completion
            // timestamp, then modification/creation time; epoch denotes unknown.
            // The Z-Push compatibility patch serializes epoch as a real date,
            // rather than emitting an empty required DateCompleted element.
            $output->datecompleted = $this->TaskCompletionTimestamp($data);
        }
        $priority = $data['priority'] ?? 0;
        $output->importance = ($priority >= 1 && $priority <= 4) ? 2 : (($priority >= 6) ? 0 : 1);
        $output->sensitivity = array('private' => 2, 'secret' => 3)[$data['privacy'] ?? 'public'] ?? 0;
        foreach (array('start' => 'startdate', 'due' => 'duedate') as $key => $property) {
            if (!empty($data[$key])) {
                $output->$property = $this->TaskTimestamp($data[$key], 'UTC');
                $utc = 'utc' . $property;
                $output->$utc = $this->TaskTimestamp($data[$key], $data['timeZone'] ?? null);
            }
        }
        $reminder = $this->TaskReminder($data);
        $output->reminderset = $reminder === null ? 0 : 1;
        if ($reminder !== null) $output->remindertime = $reminder;
        $output->categories = array_keys(array_filter($data['keywords'] ?? array()));
        $body = $data['description'] ?? '';
        if (($data['descriptionContentType'] ?? 'text/plain') === 'text/html') $body = Utils::ConvertHtmlToText($body);
        if (Request::GetProtocolVersion() >= 12.0) {
            $output->asbody = new SyncBaseBody();
            $output->asbody->type = 1;
            $output->asbody->estimatedDataSize = strlen($body);
            $size = $contentparameters->BodyPreference(1)->GetTruncationSize();
            if ($size !== false && $size !== null && $size >= 0) $body = Utils::Utf8_truncate($body, $size);
            $output->asbody->truncated = strlen($body) < $output->asbody->estimatedDataSize ? 1 : 0;
            $output->asbody->data = StringStreamWrapper::Open($body);
        } else $output->body = $body;
        // The first release exposes existing recurring tasks for reading;
        // ChangeTaskMessage explicitly rejects recurrence/assignment edits.
        return $output;
    }

    private function ChangeTaskMessage($folderid, $id, $input, $index) {
        if (!($input instanceof SyncTask)) throw new RuntimeException('Expected an ActiveSync Task');
        $existing = empty($id) ? array() : $this->TaskData($this->ReadTask($folderid, $id));
        if (!empty($input->recurrence) || !empty($input->regenerate) || !empty($existing['recurrenceRules'])
            || !empty($existing['recurrenceOverrides']) || !empty($existing['participants'])) {
            throw new RuntimeException('Recurring and assigned task edits are not supported');
        }
        $data = empty($id) ? array('@type' => 'Task', 'uid' => bin2hex(random_bytes(16)),
            'timeZone' => 'Etc/UTC', 'progress' => 'needs-action') : array();
        if (isset($input->subject)) $data['title'] = $input->subject;
        if (isset($input->importance)) $data['priority'] = array(0 => 9, 1 => 5, 2 => 1)[intval($input->importance)] ?? 5;
        if (isset($input->sensitivity)) $data['privacy'] = array(0 => 'public', 1 => 'private', 2 => 'private', 3 => 'secret')[intval($input->sensitivity)] ?? 'public';
        foreach (array('start' => 'startdate', 'due' => 'duedate') as $key => $property) {
            $utc = 'utc' . $property;
            if (isset($input->$utc) || isset($input->$property)) {
                $time = $input->$utc ?? $input->$property;
                $data[$key] = $time ? (new DateTimeImmutable('@' . intval($time)))->setTimezone(new DateTimeZone($existing['timeZone'] ?? 'Etc/UTC'))->format('Y-m-d\TH:i:s') : null;
            }
        }
        if (isset($input->complete) && (empty($id) || (bool)$input->complete !== (($existing['progress'] ?? '') === 'completed'))) {
            $data['progress'] = $input->complete ? 'completed' : 'needs-action';
            $data['percentComplete'] = $input->complete ? 100 : 0;
            if ($input->complete) {
                $data['progressUpdated'] = gmdate('Y-m-d\TH:i:s\Z', $input->datecompleted ?? time());
            } elseif (!empty($existing['progressUpdated'])) {
                // An incomplete new task has no completion timestamp to clear.
                // Older Stalwart releases reject even a null progressUpdated.
                $data['progressUpdated'] = null;
            }
        }
        if (isset($input->body)) {
            $data['description'] = $input->body;
            $data['descriptionContentType'] = 'text/plain';
        } elseif (isset($input->asbody->data)) {
            if (!in_array(intval($input->asbody->type), array(1, 2), true)) throw new RuntimeException('Unsupported task body format');
            if (!empty($input->asbody->truncated)) throw new RuntimeException('Cannot replace task notes with a truncated body');
            $body = $input->asbody->data;
            $data['description'] = is_resource($body) ? stream_get_contents($body) : strval($body);
            $data['descriptionContentType'] = intval($input->asbody->type) === 2 ? 'text/html' : 'text/plain';
        }
        if (isset($input->categories)) {
            $data['keywords'] = empty($input->categories) ? null : array_fill_keys($input->categories, true);
        }
        if (isset($input->reminderset)) {
            // ActiveSync exposes one display reminder. Preserve non-display alarms.
            if (!$input->reminderset) {
                if (!empty($existing['alerts'])) {
                    foreach ($existing['alerts'] as $alertId => $alert) {
                        if (($alert['action'] ?? 'display') === 'display') $data['alerts/' . str_replace(array('~','/'), array('~0','~1'), $alertId)] = null;
                    }
                }
            } else {
                if (!isset($input->remindertime)) throw new RuntimeException('A reminder requires a time');
                $alert = array('@type' => 'Alert', 'action' => 'display', 'trigger' => array('@type' => 'AbsoluteTrigger', 'when' => gmdate('Y-m-d\TH:i:s\Z', $input->remindertime)));
                if (empty($id) || empty($existing['alerts'])) $data['alerts'] = array('activesync' => $alert);
                else {
                    foreach (($existing['alerts'] ?? array()) as $alertId => $old) {
                        if (($old['action'] ?? 'display') === 'display') $data['alerts/' . str_replace(array('~','/'), array('~0','~1'), $alertId)] = null;
                    }
                    $data['alerts/activesync'] = $alert;
                }
            }
        }
        $creating = empty($id);
        if ($creating) $id = $this->_jmapClient->createCalendarEvent($this->_folders[$index]->jmapId, json_encode($data, JSON_THROW_ON_ERROR));
        elseif (!empty($data)) $this->_jmapClient->updateCalendarEvent($id, json_encode($data, JSON_THROW_ON_ERROR));
        $stat = $this->StatMessage($folderid, $id);
        if ($stat !== false) ZLog::Write(LOGLEVEL_INFO, 'Stalwart->ChangeTaskMessage(): ' . ($creating ? 'created' : 'updated') . ' task=' . $id . ' folder=' . $folderid);
        return $stat;
    }
}
