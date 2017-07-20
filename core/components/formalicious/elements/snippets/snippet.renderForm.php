<?php
/**
 * The base Formalicious snippet.
 *
 * @package formalicious
 */
$Formalicious = $modx->getService('formalicious','Formalicious',$modx->getOption('formalicious.core_path',null,$modx->getOption('core_path').'components/formalicious/').'model/formalicious/',$scriptProperties);
if (!($Formalicious instanceof Formalicious)) {
    return '';
}

$form = $modx->getOption('form', $scriptProperties, false);
$fieldsemailoutput = '';
$fieldSeparator = $modx->getOption('fieldSeparator', $scriptProperties, "\n");
$answerSeparator = $modx->getOption('fieldSeparator', $scriptProperties, "\n");
$stepSeparator = $modx->getOption('stepSeparator', $scriptProperties, "\n");
$formTpl = $modx->getOption('formTpl', $scriptProperties, 'formTpl');
$stepTpl = $modx->getOption('stepTpl', $scriptProperties, 'stepTpl');
$stepParam = $modx->getOption('stepParam', $scriptProperties, 'step');
$emailTpl = $modx->getOption('emailTpl', $scriptProperties, 'emailFormTpl');
$fiarTpl = $modx->getOption('fiarTpl', $scriptProperties, 'fiarTpl');
$validate = $modx->getOption('validate', $scriptProperties, false);
$customValidators = $modx->getOption('customValidators', $scriptProperties, '');
$currentStep = $modx->getOption($stepParam, $_GET, 1);
$finishStep = false;
$validation = array();
$output = array();
$hooks = array('spam', 'FormaliciousSaveValues');
$preHooks = array('FormaliciousGetValues');
$requestArr = $_REQUEST;
/* Get form */
if ($form) {
    $form = $modx->getObject('FormaliciousForm', $form);
    if ($form) {
        /* Merge values stored in Session and request. Request is leading. */
        $sessionKey = 'Formalicious_form_'.$form->get('id');
        if (isset($_SESSION[$sessionKey]) &&
            is_array($_SESSION[$sessionKey])
        ) {
            $requestArr = array_merge($_SESSION[$sessionKey], $requestArr);
        }

        if (!$form->get('published')) {
            return '<i>'.
                $modx->lexicon(
                    'formalicious.form.notpublished',
                    array(
                        'id' => $form->get('id'),
                        'form' => $form->get('name')
                    )
                )
                . '</i>';
        }
        $phs = $form->toArray();

        /**
         * Load the FormIt class and run the prehooks.
         * This has to be done to be able to get the correct values from $hook->getValue() calls
        */
        if ($phs['prehooks']) {
            $modelPath = $modx->getOption(
                'formit.core_path',
                null,
                $modx->getOption('core_path', null, MODX_CORE_PATH) . 'components/formit/'
            ) . 'model/formit/';
            $modx->loadClass('FormIt', $modelPath, true, true);
            $fi = new FormIt($modx);
            $fi->initialize('web');
            $fi->config['preHooks'] = $phs['prehooks'];
            $request = $fi->loadRequest();
            $fields = $request->prepare();
            $request->handle($fields);
        }

        /* Add the custom hooks */
        if ($phs['posthooks']) {
            $customPostHooks = explode(',', trim($phs['posthooks']));
            if (count($customPostHooks)) {
                $hooks = array_merge($hooks, $customPostHooks);
            }
        }
        $stepsC = $modx->newQuery('FormaliciousStep');
        $stepsC->sortby('rank', 'ASC');
        $steps = $form->getMany('Steps', $stepsC);
        $totalSteps = count($steps);
        if ($currentStep == $totalSteps) {
            $finishStep = true;
        }
        foreach ($steps as $step) {
            $stepInner = array();
            $validationStep = array();
            $fieldsC = $modx->newQuery('FormaliciousField');
            $fieldsC->where(array('published' => 1));
            $fieldsC->sortby('rank', 'ASC');
            $fields = $step->getMany('Fields', $fieldsC);
            $fieldsemailoutput .= '<table><tbody>';
            foreach ($fields as $field) {
                $fieldsemailoutput .= '<tr>';
                $fieldsemailoutput .= '<td><strong>' . $field->get('title') . '</strong></td>';
                $fieldsemailoutput .= '<td>[[+field_' . $field->get('id') . ':default=``]]</td>';
                $fieldsemailoutput .= '</tr>';
                $validationStep['field_'.$field->get('id')] = array();
                $answerOuter = array();
                $type = $field->getOne('Type');
                $answerC = $modx->newQuery('FormaliciousAnswer');
                $answerC->where(array('published' => 1));
                $answerC->sortby('rank', 'ASC');
                $answers = $field->getMany('Answers', $answerC);
                $answerIdx = 1;
                foreach ($answers as $answer) {
                    $answerPhs = $answer->toArray();
                    $answerPhs['uniqid'] = uniqid();
                    $answerPhs['fieldname'] = 'field_'.$field->get('id');
                    //Dirty fix for output filters on checkboxes, radio buttons and selects.
                    $value = $requestArr['field_'.$field->get('id')];
                    $value = is_array($value) ? $modx->toJSON($value) : $value;
                    $answerPhs['curval'] = $value;
                    $answerPhs['sessionkey'] = $sessionKey;
                    $answerPhs['idx'] = $answerIdx;
                    $answerOuter[] = $modx->getChunk($type->get('answertpl'), $answerPhs);
                    $answerIdx++;
                }
                $fieldPhs = $field->toArray();
                $fieldPhs['uniqid'] = uniqid();
                $fieldPhs['values'] = implode($answerSeparator, $answerOuter);
                if ($field->get('required')) {
                    $validationStep['field_'.$field->get('id')][] = 'required';
                    $fieldPhs['title'] .= ' *';
                }
                if ($type) {
                    $stepInner[] = $modx->getChunk($type->get('tpl'), $fieldPhs);
                    if ($type->get('validation') != '') {
                        foreach (explode(',', $type->get('validation')) as $validationRule) {
                            $validationStep['field_'.$field->get('id')][] = $validationRule;
                        }
                    }
                    $fieldNames['field_'.$field->get('id')] = $field->get('title');
                    //$stepInner[] = $field->get('name');
                } else {
                    // error type doesn't exists
                }
                if (count($validationStep['field_'.$field->get('id')]) == 0) {
                    unset($validationStep['field_'.$field->get('id')]);
                }
            }
            $fieldsemailoutput .= '</tbody></table>';

            $stepPhs = $step->toArray();
            $stepPhs['fields'] = implode($fieldSeparator, $stepInner);
            if ($stepTpl) {
                $stepPhs['totalSteps'] = $totalSteps;
                $output[] = $modx->getChunk($stepTpl, $stepPhs);
            } else {
                $output[] = $stepPhs['fields'];
            }
            $validation[] = $validationStep;
        }
        if (!$form->get('onepage')) {
            $forminner = $output[$currentStep - 1];
            $validationCurrent = $validation[$currentStep - 1];
        } else {
            $finishStep = true;
            $forminner = implode($stepSeparator, $output);
            $validationCurrent = implode('', $validation);
        }

        $formPhs = $form->toArray();

        if ($finishStep) {
            // Only add email hook when emailto field is set
            if ($form->get('emailto')) {
                $hooks[] = 'email';
            }
            if ($form->get('saveform')) {
                $hooks[] = 'FormItSaveForm';
            }

            if ($form->get('fiaremail') && $form->get('fiaremail') == 1) {
                $hooks[] = 'FormItAutoResponder';
            }

            $hooks[] = 'FormaliciousRemoveValues';
            $hooks[] = 'redirect';

            $redirectTo = $form->get('redirectto');
            $redirectParams = '';
            $formPhs['submitTitle'] = $modx->lexicon('formalicious.submit');
        } else {
            $hooks[] = 'redirect';

            $redirectTo = $modx->resource->get('id');
            $redirectParams = $modx->toJSON(array($stepParam => $currentStep + 1));
            $formPhs['submitTitle'] = $modx->lexicon('formalicious.next');
        }

        $formPhs['fieldsemailoutput'] = $fieldsemailoutput;
        $formPhs['form'] = $forminner;
        $formPhs['redirectTo'] = $redirectTo;
        $formPhs['stepParam'] = $stepParam;
        $formPhs['emailTpl'] = $emailTpl;
        $formPhs['fiarTpl'] = $fiarTpl;
        $formPhs['redirectParams'] = $redirectParams;
        $formPhs['currentStep'] = $currentStep;
        $formPhs['hooks'] = implode(',', $hooks);
        $formPhs['preHooks'] = implode(',', $preHooks);
        $formPhs['validation'] = implode(
            ',',
            array_map(
                function ($v, $k) {
                    return sprintf('%s:%s', $k, implode(':', $v));
                },
                $validationCurrent,
                array_keys($validationCurrent)
            )
        );
        // Add the validate parameter specified in the renderForm snippet call
        if ($validate) {
            if (count($validationCurrent)) {
                $validate = ','.$validate;
            }
            $formPhs['validation'] .= $validate;
        }
        $formPhs['customValidators'] = $customValidators;
        $formPhs['fieldNames'] = implode(
            ',',
            array_map(
                function ($v, $k) {
                    return $k.'=='.$v;
                },
                $fieldNames,
                array_keys($fieldNames)
            )
        );
        $formPhs['fieldNames'] = implode(
            ',',
            array_map(
                function ($v, $k) {
                    return $k.'=='.$v;
                },
                $fieldNames,
                array_keys($fieldNames)
            )
        );
        $formPhs['formFields'] = implode(',', array_keys($fieldNames));

        /* Set the custom Formit parameters */
        $formParams = '';
        if ($formPhs['parameters']) {
            $parameters = json_decode($formPhs['parameters'], true);
            if (count($parameters)) {
                foreach ($parameters as $param) {
                    if ($param['key'] && $param['value']) {
                        $formParams .= '&' . $param['key'] . '=`' . $param['value'] . '`';
                    }
                }
            }
        }
        $formPhs['parameters'] = $formParams;
        return $modx->getChunk($formTpl, $formPhs);
    }
}